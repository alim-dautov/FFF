<?php
declare(strict_types=1);

final class WhatsAppService
{
    public static function conversations(Db $db, array $actor, string $q): array
    {
        actor_require($actor, 'whatsapp.view');
        $params = [];
        $sql = 'SELECT w.*, c.full_name, e.full_name AS manager_name
                FROM whatsapp_conversations w
                LEFT JOIN customers c ON c.id = w.customer_id
                LEFT JOIN employees e ON e.id = w.manager_id
                WHERE 1=1';
        if (!actor_can($actor, 'customers.view_all') && !actor_can($actor, 'leads.view_all')) {
            $sql .= ' AND (w.manager_id = :manager OR w.manager_id IS NULL)';
            $params['manager'] = $actor['employee_id'];
        }
        if ($q !== '') {
            $sql .= ' AND (w.phone LIKE :q OR w.phone_digits LIKE :digits OR c.full_name LIKE :q)';
            $params['q'] = '%' . $q . '%';
            $params['digits'] = '%' . phone_digits($q) . '%';
        }
        return ['data' => $db->all($sql . ' ORDER BY w.last_message_at DESC, w.id DESC', $params)];
    }

    public static function messages(Db $db, array $actor, int $id): array
    {
        actor_require($actor, 'whatsapp.view');
        $conversation = self::must($db, $id);
        $db->exec('UPDATE whatsapp_conversations SET unread_count = 0 WHERE id = :id', ['id' => $id]);
        return [
            'conversation' => $conversation,
            'messages' => $db->all('SELECT * FROM whatsapp_messages WHERE conversation_id = :id ORDER BY id', ['id' => $id]),
            'templates' => $db->all('SELECT id, code, name, body FROM message_templates ORDER BY id'),
            'channel_ready' => false,
        ];
    }

    public static function send(Db $db, array $config, array $actor, int $id, string $body): array
    {
        actor_require($actor, 'whatsapp.send');
        $conversation = self::must($db, $id);
        $body = trim($body);
        if ($body === '') {
            throw new HttpError(422, 'Введите текст сообщения');
        }
        $ready = CommService::channelReady($db, $config);
        $status = $ready ? 'queued' : 'draft';
        $messageId = $db->insert(
            'INSERT INTO whatsapp_messages (conversation_id, direction, body, status, user_id, created_at)
             VALUES (:conversation, :direction, :body, :status, :user, :created)',
            [
                'conversation' => $id,
                'direction' => 'out',
                'body' => $body,
                'status' => $status,
                'user' => $actor['id'],
                'created' => app_now(),
            ],
        );
        $db->insert(
            'INSERT INTO outbound_messages (customer_id, channel, body, status, created_by, created_at)
             VALUES (:customer, :channel, :body, :status, :user, :created)',
            [
                'customer' => $conversation['customer_id'],
                'channel' => 'whatsapp',
                'body' => $body,
                'status' => $status,
                'user' => $actor['id'],
                'created' => app_now(),
            ],
        );
        if ($ready) {
            try {
                WhatsAppProvider::send($config, $conversation['phone'], $body);
                $status = 'sent';
            } catch (Throwable) {
                $status = 'failed';
            }
            $db->exec('UPDATE whatsapp_messages SET status = :status WHERE id = :id', ['status' => $status, 'id' => $messageId]);
        }
        $db->exec('UPDATE whatsapp_conversations SET last_message_at = :at WHERE id = :id', ['at' => app_now(), 'id' => $id]);
        if ($conversation['customer_id']) {
            $db->exec('UPDATE customers SET last_contact_at = :at WHERE id = :id', ['at' => app_today(), 'id' => $conversation['customer_id']]);
        }
        return ['id' => $messageId, 'status' => $status, 'channel_ready' => $ready, 'body' => $body];
    }

    public static function openForCustomer(Db $db, array $actor, int $customerId): array
    {
        actor_require($actor, 'whatsapp.view');
        $customer = BillingService::customer($db, $actor, $customerId);
        $existing = $db->one('SELECT * FROM whatsapp_conversations WHERE customer_id = :id ORDER BY id DESC LIMIT 1', ['id' => $customerId]);
        if ($existing) {
            return $existing;
        }
        $id = $db->insert(
            'INSERT INTO whatsapp_conversations (customer_id, phone, phone_digits, manager_id, unread_count, created_at)
             VALUES (:customer, :phone, :digits, :manager, 0, :created)',
            [
                'customer' => $customerId,
                'phone' => $customer['whatsapp'] ?: $customer['phone'],
                'digits' => phone_digits($customer['whatsapp'] ?: $customer['phone']),
                'manager' => $customer['manager_id'],
                'created' => app_now(),
            ],
        );
        return $db->one('SELECT * FROM whatsapp_conversations WHERE id = :id', ['id' => $id]);
    }

    public static function link(Db $db, array $actor, int $id, int $customerId): array
    {
        actor_require($actor, 'whatsapp.send');
        self::must($db, $id);
        BillingService::customer($db, $actor, $customerId);
        $db->exec('UPDATE whatsapp_conversations SET customer_id = :customer WHERE id = :id', ['customer' => $customerId, 'id' => $id]);
        return $db->one('SELECT * FROM whatsapp_conversations WHERE id = :id', ['id' => $id]);
    }

    public static function convert(Db $db, array $actor, int $id): array
    {
        actor_require($actor, 'customers.manage');
        $conversation = self::must($db, $id);
        if ($conversation['customer_id']) {
            return ['customer_id' => (int) $conversation['customer_id']];
        }
        $card = CrmService::createCustomer($db, $actor, [
            'full_name' => 'Клиент ' . $conversation['phone'],
            'phone' => $conversation['phone'],
            'source' => 'whatsapp',
            'manager_id' => $conversation['manager_id'] ?: $actor['employee_id'],
        ]);
        $customerId = (int) $card['customer']['id'];
        $db->exec('UPDATE whatsapp_conversations SET customer_id = :customer WHERE id = :id', ['customer' => $customerId, 'id' => $id]);
        return ['customer_id' => $customerId];
    }

    public static function assign(Db $db, array $actor, int $id, int $managerId): void
    {
        actor_require($actor, 'whatsapp.send');
        self::must($db, $id);
        $db->exec('UPDATE whatsapp_conversations SET manager_id = :manager WHERE id = :id', ['manager' => $managerId, 'id' => $id]);
    }

    public static function taskFromMessage(Db $db, array $config, array $actor, int $id, array $input): array
    {
        $conversation = self::must($db, $id);
        $input['customer_id'] = $conversation['customer_id'];
        $input['title'] = $input['title'] ?? 'Ответить в WhatsApp';
        $input['description'] = $input['description'] ?? ($input['message'] ?? '');
        return PeopleService::saveTask($db, $config, $actor, null, $input);
    }

    private static function must(Db $db, int $id): array
    {
        $row = $db->one('SELECT * FROM whatsapp_conversations WHERE id = :id', ['id' => $id]);
        if (!$row) {
            throw new HttpError(404, 'Диалог не найден');
        }
        return $row;
    }
}
