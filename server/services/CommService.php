<?php
declare(strict_types=1);

final class CommService
{
    public static function notifyUser(Db $db, array $config, int $userId, string $level, string $title, string $body, ?string $entity, ?int $entityId, ?string $link): int
    {
        $id = $db->insert(
            'INSERT INTO notifications (user_id, level, title, body, entity_type, entity_id, link, created_at)
             VALUES (:user_id, :level, :title, :body, :entity, :entity_id, :link, :created)',
            [
                'user_id' => $userId,
                'level' => $level,
                'title' => $title,
                'body' => $body,
                'entity' => $entity,
                'entity_id' => $entityId,
                'link' => $link,
                'created' => app_now(),
            ],
        );
        self::push($db, $config, $userId, $title, $body, $link ?: '/notifications');
        return $id;
    }

    public static function notifyEmployee(Db $db, array $config, ?int $employeeId, string $level, string $title, string $body, ?string $entity, ?int $entityId, ?string $link): void
    {
        if ($employeeId) {
            $user = $db->one('SELECT id FROM users WHERE employee_id = :id AND status = :status', ['id' => $employeeId, 'status' => 'active']);
            if ($user) {
                self::notifyUser($db, $config, (int) $user['id'], $level, $title, $body, $entity, $entityId, $link);
                return;
            }
        }
        self::notifyPermission($db, $config, 'settings.manage', $level, $title, $body, $entity, $entityId, $link);
    }

    public static function notifyPermission(Db $db, array $config, string $permission, string $level, string $title, string $body, ?string $entity, ?int $entityId, ?string $link): void
    {
        $users = $db->all(
            'SELECT DISTINCT u.id FROM users u
             JOIN role_permissions rp ON rp.role_id = u.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.code = :code AND u.status = :status',
            ['code' => $permission, 'status' => 'active'],
        );
        foreach ($users as $user) {
            self::notifyUser($db, $config, (int) $user['id'], $level, $title, $body, $entity, $entityId, $link);
        }
    }

    public static function channelReady(Db $db, array $config): bool
    {
        return setting($db, 'whatsapp_enabled', '0') === '1'
            && ($config['whatsapp_token'] ?? '') !== ''
            && ($config['whatsapp_phone_id'] ?? '') !== '';
    }

    public static function templateVars(Db $db, int $customerId, array $config): array
    {
        $customer = $db->one('SELECT * FROM customers WHERE id = :id', ['id' => $customerId]);
        if (!$customer) {
            return [];
        }
        $sub = $db->one(
            'SELECT s.*, p.name AS program_name FROM subscriptions s
             JOIN meal_programs p ON p.id = s.program_id
             WHERE s.customer_id = :id ORDER BY s.id DESC LIMIT 1',
            ['id' => $customerId],
        );
        $manager = $customer['manager_id']
            ? $db->one('SELECT full_name FROM employees WHERE id = :id', ['id' => $customer['manager_id']])
            : null;
        $link = $db->one(
            'SELECT l.token FROM payment_links l
             JOIN payments pay ON pay.id = l.payment_id
             WHERE pay.customer_id = :id AND pay.status = :status
             ORDER BY l.id DESC LIMIT 1',
            ['id' => $customerId, 'status' => 'pending'],
        );
        $discount = $sub ? (int) $sub['discount_amount'] : 0;
        return [
            'name' => $customer['full_name'],
            'phone' => $customer['phone'],
            'subscription_end' => $sub ? (new DateTimeImmutable($sub['end_date']))->format('d.m.Y') : '',
            'program' => $sub['program_name'] ?? '',
            'price' => $sub ? number_format((int) $sub['total_amount'], 0, '.', ' ') . ' ₸' : '',
            'manager' => $manager['full_name'] ?? '',
            'discount' => $discount > 0 ? number_format($discount, 0, '.', ' ') . ' ₸' : '0 ₸',
            'payment_link' => $link ? rtrim($config['app_url'], '/') . '/pay/' . $link['token'] : '',
        ];
    }

    public static function queueTemplate(Db $db, array $config, int $customerId, string $templateCode, ?int $userId): array
    {
        $template = $db->one('SELECT * FROM message_templates WHERE code = :code', ['code' => $templateCode]);
        if (!$template) {
            throw new HttpError(404, 'Шаблон не найден');
        }
        $body = render_template($template['body'], self::templateVars($db, $customerId, $config));
        $ready = self::channelReady($db, $config);
        $status = $ready ? 'queued' : 'draft';
        $id = $db->insert(
            'INSERT INTO outbound_messages (customer_id, template_code, channel, body, status, created_by, created_at)
             VALUES (:customer, :code, :channel, :body, :status, :user, :created)',
            [
                'customer' => $customerId,
                'code' => $templateCode,
                'channel' => 'whatsapp',
                'body' => $body,
                'status' => $status,
                'user' => $userId,
                'created' => app_now(),
            ],
        );
        if ($ready) {
            try {
                WhatsAppProvider::send($config, $db->one('SELECT phone FROM customers WHERE id = :id', ['id' => $customerId])['phone'] ?? '', $body);
                $db->exec('UPDATE outbound_messages SET status = :status WHERE id = :id', ['status' => 'sent', 'id' => $id]);
                $status = 'sent';
            } catch (Throwable $e) {
                $db->exec('UPDATE outbound_messages SET status = :status WHERE id = :id', ['status' => 'failed', 'id' => $id]);
                $status = 'failed';
            }
        }
        return ['id' => $id, 'status' => $status, 'body' => $body, 'channel_ready' => $ready];
    }

    public static function listNotifications(Db $db, array $actor): array
    {
        actor_require($actor, 'notifications.view');
        return $db->all(
            'SELECT * FROM notifications WHERE user_id = :id ORDER BY id DESC LIMIT 100',
            ['id' => $actor['id']],
        );
    }

    public static function markRead(Db $db, array $actor, array $input): void
    {
        actor_require($actor, 'notifications.view');
        if (!empty($input['all'])) {
            $db->exec(
                'UPDATE notifications SET read_at = :at WHERE user_id = :id AND read_at IS NULL',
                ['at' => app_now(), 'id' => $actor['id']],
            );
            return;
        }
        foreach ($input['ids'] ?? [] as $id) {
            $db->exec(
                'UPDATE notifications SET read_at = :at WHERE id = :id AND user_id = :user AND read_at IS NULL',
                ['at' => app_now(), 'id' => (int) $id, 'user' => $actor['id']],
            );
        }
    }

    public static function savePush(Db $db, array $actor, array $input): void
    {
        $endpoint = trim((string) ($input['endpoint'] ?? ''));
        if ($endpoint === '' || empty($input['keys']['p256dh']) || empty($input['keys']['auth'])) {
            throw new HttpError(422, 'Некорректная push-подписка');
        }
        $exists = $db->one('SELECT id FROM push_subscriptions WHERE user_id = :user AND endpoint = :endpoint', [
            'user' => $actor['id'],
            'endpoint' => $endpoint,
        ]);
        if ($exists) {
            return;
        }
        $db->insert(
            'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth_key, created_at)
             VALUES (:user, :endpoint, :p256dh, :auth, :created)',
            [
                'user' => $actor['id'],
                'endpoint' => $endpoint,
                'p256dh' => (string) $input['keys']['p256dh'],
                'auth' => (string) $input['keys']['auth'],
                'created' => app_now(),
            ],
        );
    }

    private static function push(Db $db, array $config, int $userId, string $title, string $body, string $url): void
    {
        if (($config['vapid_public'] ?? '') === '' || ($config['vapid_private'] ?? '') === '') {
            return;
        }
        $subs = $db->all('SELECT endpoint, p256dh, auth_key FROM push_subscriptions WHERE user_id = :id', ['id' => $userId]);
        if (!$subs) {
            return;
        }
        $script = dirname(__DIR__, 2) . '/scripts/send-push.mjs';
        if (!is_file($script)) {
            return;
        }
        $payload = json_encode([
            'subs' => array_map(static fn (array $s) => [
                'endpoint' => $s['endpoint'],
                'keys' => ['p256dh' => $s['p256dh'], 'auth' => $s['auth_key']],
            ], $subs),
            'payload' => ['title' => $title, 'body' => $body, 'url' => $url],
            'vapid' => [
                'subject' => $config['vapid_subject'],
                'publicKey' => $config['vapid_public'],
                'privateKey' => $config['vapid_private'],
            ],
        ], JSON_UNESCAPED_UNICODE);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open('node ' . escapeshellarg($script), $descriptors, $pipes, dirname(__DIR__, 2));
        if (!is_resource($proc)) {
            return;
        }
        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
    }
}

final class WhatsAppProvider
{
    public static function send(array $config, string $phone, string $body): void
    {
        $token = $config['whatsapp_token'] ?? '';
        $phoneId = $config['whatsapp_phone_id'] ?? '';
        if ($token === '' || $phoneId === '') {
            throw new HttpError(503, 'WhatsApp-канал не настроен', 'channel_off');
        }
        $digits = phone_digits($phone);
        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'to' => $digits,
            'type' => 'text',
            'text' => ['body' => $body],
        ], JSON_UNESCAPED_UNICODE);
        $ch = curl_init('https://graph.facebook.com/v21.0/' . rawurlencode($phoneId) . '/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $code >= 400) {
            throw new HttpError(502, 'WhatsApp-провайдер не принял сообщение');
        }
    }
}
