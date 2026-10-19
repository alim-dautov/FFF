<?php
declare(strict_types=1);

final class CrmService
{
    private const STAGES = [
        'new', 'contacted', 'qualified', 'program_selected', 'proposal_sent',
        'awaiting_payment', 'paid', 'active_client', 'rejected', 'no_answer',
    ];

    private const FILTERS = [
        'all', 'active', 'new', 'renewal', 'ending_3', 'ending_tomorrow',
        'ending_today', 'overdue', 'paused', 'lost', 'vip',
    ];

    public static function customerScope(array $actor, string $alias = 'c'): array
    {
        return self::scope($actor, $alias);
    }

    public static function listCustomers(Db $db, array $actor, array $query): array
    {
        if (($query['view'] ?? '') === 'nutrition') {
            return NutritionService::list($db, $actor, $query);
        }
        actor_require($actor, 'customers.view');
        $filter = (string) ($query['filter'] ?? 'all');
        if (!in_array($filter, self::FILTERS, true)) {
            $filter = 'all';
        }
        [$scope, $params] = self::scope($actor, 'c');
        $where = ' WHERE 1=1' . $scope . self::filterSql($filter, $params);
        if (!empty($query['q'])) {
            $q = trim((string) $query['q']);
            $where .= ' AND (c.full_name LIKE :q OR c.phone LIKE :q OR c.phone_digits LIKE :digits OR c.city LIKE :q)';
            $params['q'] = '%' . $q . '%';
            $params['digits'] = '%' . phone_digits($q) . '%';
        }
        $from = 'FROM customers c
            LEFT JOIN employees e ON e.id = c.manager_id
            LEFT JOIN subscriptions s ON s.id = (SELECT MAX(s2.id) FROM subscriptions s2 WHERE s2.customer_id = c.id)
            LEFT JOIN meal_programs p ON p.id = s.program_id' . $where;
        $page = clamp_page($query['page'] ?? 1);
        $per = clamp_per_page($query['per_page'] ?? 20);
        $total = (int) $db->one('SELECT COUNT(*) AS c ' . $from, $params)['c'];
        $rows = $db->all(
            'SELECT c.*, e.full_name AS manager_name, p.name AS program_name, s.id AS subscription_id,
                    s.start_date, s.end_date, s.total_amount, s.payment_status, s.status AS subscription_status
             ' . $from . ' ORDER BY c.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per),
            $params,
        );
        foreach ($rows as &$row) {
            $row['days_left'] = days_left($row['end_date'] ?? null);
            $row['vip'] = (int) $row['vip'];
            $row['total_amount'] = $row['total_amount'] !== null ? (int) $row['total_amount'] : null;
        }
        return [
            'data' => $rows,
            'page' => $page,
            'per_page' => $per,
            'total' => $total,
            'summary' => self::summary($db, $actor),
        ];
    }

    public static function summary(Db $db, array $actor): array
    {
        $count = function (string $filter) use ($db, $actor): int {
            [$scope, $params] = self::scope($actor, 'c');
            $where = ' WHERE 1=1' . $scope . self::filterSql($filter, $params);
            $sql = 'SELECT COUNT(*) AS c FROM customers c
                LEFT JOIN subscriptions s ON s.id = (SELECT MAX(s2.id) FROM subscriptions s2 WHERE s2.customer_id = c.id)' . $where;
            return (int) $db->one($sql, $params)['c'];
        };
        return [
            'today' => $count('ending_today'),
            'tomorrow' => $count('ending_tomorrow'),
            'in_3_days' => $count('ending_3'),
            'overdue' => $count('overdue'),
            'renewal' => $count('renewal'),
        ];
    }

    public static function getCustomer(Db $db, array $actor, int $id): array
    {
        actor_require($actor, 'customers.view');
        $customer = BillingService::customer($db, $actor, $id);
        $manager = $customer['manager_id']
            ? $db->one('SELECT id, full_name FROM employees WHERE id = :id', ['id' => $customer['manager_id']])
            : null;
        $address = $db->one('SELECT * FROM customer_addresses WHERE customer_id = :id AND is_primary = 1 ORDER BY id DESC LIMIT 1', ['id' => $id]);
        $schedule = $db->all('SELECT * FROM delivery_schedule_days WHERE customer_id = :id ORDER BY weekday', ['id' => $id]);
        $subs = $db->all(
            'SELECT s.*, p.name AS program_name, p.calories, p.meals_per_day, p.description
             FROM subscriptions s JOIN meal_programs p ON p.id = s.program_id
             WHERE s.customer_id = :id ORDER BY s.id DESC',
            ['id' => $id],
        );
        foreach ($subs as &$sub) {
            $sub['days_left'] = days_left($sub['end_date']);
            $sub['items'] = $db->all('SELECT * FROM subscription_items WHERE subscription_id = :id', ['id' => $sub['id']]);
        }
        $payments = $db->all(
            'SELECT p.*, e.full_name AS manager_name FROM payments p
             LEFT JOIN employees e ON e.id = p.manager_id
             WHERE p.customer_id = :id ORDER BY p.id DESC',
            ['id' => $id],
        );
        $deliveries = $db->all('SELECT * FROM deliveries WHERE customer_id = :id ORDER BY delivery_date DESC, id DESC LIMIT 30', ['id' => $id]);
        $tasks = $db->all(
            'SELECT t.*, e.full_name AS assignee_name FROM tasks t
             LEFT JOIN employees e ON e.id = t.assignee_id
             WHERE t.customer_id = :id ORDER BY t.id DESC',
            ['id' => $id],
        );
        $conversation = $db->one('SELECT id FROM whatsapp_conversations WHERE customer_id = :id ORDER BY id DESC LIMIT 1', ['id' => $id]);
        $messages = $conversation
            ? $db->all('SELECT * FROM whatsapp_messages WHERE conversation_id = :id ORDER BY id ASC', ['id' => $conversation['id']])
            : [];
        $documents = $db->all('SELECT id, name, mime, size, created_at FROM customer_documents WHERE customer_id = :id ORDER BY id DESC', ['id' => $id]);
        $discounts = $db->all(
            'SELECT d.*, e.full_name AS given_name FROM discounts d LEFT JOIN employees e ON e.id = d.given_by
             WHERE d.customer_id = :id ORDER BY d.id DESC',
            ['id' => $id],
        );
        $paid = array_values(array_filter($payments, fn ($p) => $p['status'] === 'paid'));
        $sum = array_sum(array_map(fn ($p) => (int) $p['amount'], $paid));
        $current = $subs[0] ?? null;
        $rations = NutritionService::forCustomer($db, $id);
        return [
            'customer' => $customer,
            'manager' => $manager,
            'address' => $address,
            'schedule' => $schedule,
            'subscriptions' => $subs,
            'rations' => $rations,
            'current' => $current,
            'payments' => $payments,
            'deliveries' => $deliveries,
            'tasks' => $tasks,
            'conversation_id' => $conversation['id'] ?? null,
            'messages' => $messages,
            'documents' => $documents,
            'discounts' => $discounts,
            'stats' => [
                'orders' => (int) $db->one('SELECT COUNT(*) AS c FROM orders WHERE customer_id = :id', ['id' => $id])['c'],
                'ltv' => $sum,
                'avg_check' => count($paid) ? (int) round($sum / count($paid)) : 0,
                'lifetime_days' => days_left($customer['registered_at']) !== null ? abs((int) days_left($customer['registered_at'])) : 0,
            ],
            'timeline' => self::timeline($db, $id),
        ];
    }

    public static function createCustomer(Db $db, array $actor, array $input): array
    {
        actor_require($actor, 'customers.manage');
        $name = trim((string) ($input['full_name'] ?? ''));
        $phone = phone_format(phone_digits((string) ($input['phone'] ?? '')));
        if ($name === '' || strlen(phone_digits($phone)) !== 11) {
            throw new HttpError(422, 'Укажите имя и телефон в формате +7');
        }
        $id = $db->insert(
            'INSERT INTO customers (full_name, phone, phone_digits, whatsapp, status, vip, manager_id, source, city, district, comment, first_contact_at, registered_at, created_at, name_key)
             VALUES (:name, :phone, :digits, :whatsapp, :status, :vip, :manager, :source, :city, :district, :comment, :contact, :registered, :created, :name_key)',
            [
                'name' => $name,
                'phone' => $phone,
                'digits' => phone_digits($phone),
                'whatsapp' => phone_format(phone_digits((string) ($input['whatsapp'] ?? $phone))),
                'status' => 'new',
                'vip' => !empty($input['vip']) ? 1 : 0,
                'manager' => (int) ($input['manager_id'] ?? $actor['employee_id'] ?? 0) ?: null,
                'source' => (string) ($input['source'] ?? 'manual'),
                'city' => (string) ($input['city'] ?? 'Алматы'),
                'district' => (string) ($input['district'] ?? ''),
                'comment' => $input['comment'] ?? null,
                'contact' => app_today(),
                'registered' => app_today(),
                'created' => app_now(),
                'name_key' => name_key($name),
            ],
        );
        self::saveAddress($db, $id, $input['address'] ?? [
            'city' => $input['city'] ?? 'Алматы',
            'district' => $input['district'] ?? '',
            'street' => $input['street'] ?? '',
            'building' => $input['building'] ?? '',
        ]);
        audit_change($db, $actor['id'], 'customers', $id, 'create', 'full_name', null, $name);
        return self::getCustomer($db, $actor, $id);
    }

    public static function updateCustomer(Db $db, array $actor, int $id, array $input): array
    {
        actor_require($actor, 'customers.manage');
        $before = BillingService::customer($db, $actor, $id);
        $fields = [];
        foreach (['full_name', 'status', 'source', 'city', 'district', 'comment', 'next_action', 'next_action_at', 'last_contact_at'] as $field) {
            if (array_key_exists($field, $input)) {
                $fields[$field] = $input[$field] === '' ? null : $input[$field];
            }
        }
        if (array_key_exists('full_name', $fields) && $fields['full_name'] !== null) {
            $fields['name_key'] = name_key((string) $fields['full_name']);
        }
        if (array_key_exists('vip', $input)) {
            $fields['vip'] = !empty($input['vip']) ? 1 : 0;
        }
        if (array_key_exists('manager_id', $input)) {
            $fields['manager_id'] = $input['manager_id'] ? (int) $input['manager_id'] : null;
        }
        if (!empty($input['phone'])) {
            $digits = phone_digits((string) $input['phone']);
            if (strlen($digits) !== 11) {
                throw new HttpError(422, 'Телефон должен быть в формате +7');
            }
            $fields['phone'] = phone_format($digits);
            $fields['phone_digits'] = $digits;
        }
        if (!empty($input['whatsapp'])) {
            $fields['whatsapp'] = phone_format(phone_digits((string) $input['whatsapp']));
        }
        if ($fields) {
            $sets = [];
            $params = ['id' => $id];
            foreach ($fields as $key => $value) {
                $sets[] = $key . ' = :' . $key;
                $params[$key] = $value;
            }
            $db->exec('UPDATE customers SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
            audit_fields($db, $actor['id'], 'customers', $id, $before, $fields);
        }
        if (isset($input['address']) && is_array($input['address'])) {
            self::saveAddress($db, $id, $input['address']);
        }
        if (isset($input['schedule']) && is_array($input['schedule'])) {
            self::saveSchedule($db, $id, $input['schedule']);
        }
        return self::getCustomer($db, $actor, $id);
    }

    public static function bulk(Db $db, array $config, array $actor, array $input): array
    {
        actor_require($actor, 'customers.manage');
        $ids = array_values(array_unique(array_map('intval', $input['ids'] ?? [])));
        if (!$ids || count($ids) > 50) {
            throw new HttpError(422, 'Выберите от 1 до 50 клиентов');
        }
        $action = (string) ($input['action'] ?? '');
        $results = [];
        foreach ($ids as $id) {
            BillingService::customer($db, $actor, $id);
            if ($action === 'renewal_message') {
                $results[] = CommService::queueTemplate($db, $config, $id, 'renew_offer', $actor['id']);
            } else {
                throw new HttpError(422, 'Неизвестное массовое действие');
            }
        }
        return ['results' => $results];
    }

    public static function discount(Db $db, array $actor, int $customerId, array $input): array
    {
        actor_require($actor, 'customers.manage');
        BillingService::customer($db, $actor, $customerId);
        $subId = (int) ($input['subscription_id'] ?? 0);
        $sub = $subId
            ? $db->one('SELECT * FROM subscriptions WHERE id = :id AND customer_id = :customer', ['id' => $subId, 'customer' => $customerId])
            : $db->one('SELECT * FROM subscriptions WHERE customer_id = :customer ORDER BY id DESC LIMIT 1', ['customer' => $customerId]);
        if (!$sub) {
            throw new HttpError(422, 'Нет подписки для скидки');
        }
        $base = (int) $sub['price'];
        $percent = isset($input['percent']) && $input['percent'] !== '' ? (int) $input['percent'] : null;
        if ($percent !== null) {
            if ($percent < 0 || $percent > 90) {
                throw new HttpError(422, 'Скидка должна быть от 0 до 90%');
            }
            $amount = (int) round($base * $percent / 100);
        } else {
            $amount = money_int($input['amount'] ?? 0);
        }
        $amount = max(0, min($base, $amount));
        $final = $base - $amount;
        $db->exec(
            'UPDATE subscriptions SET discount_amount = :discount, total_amount = :total WHERE id = :id',
            ['discount' => $amount, 'total' => $final, 'id' => $sub['id']],
        );
        $db->insert(
            'INSERT INTO discounts (customer_id, subscription_id, percent, amount, base_amount, final_amount, reason, given_by, renewed_after, created_at)
             VALUES (:customer, :sub, :percent, :amount, :base, :final, :reason, :by, 0, :created)',
            [
                'customer' => $customerId,
                'sub' => $sub['id'],
                'percent' => $percent,
                'amount' => $amount,
                'base' => $base,
                'final' => $final,
                'reason' => $input['reason'] ?? 'Удержание',
                'by' => $actor['employee_id'],
                'created' => app_now(),
            ],
        );
        audit_change($db, $actor['id'], 'discounts', $customerId, 'create', 'amount', $base, $final);
        return ['price' => $base, 'discount' => $amount, 'percent' => $percent, 'total' => $final];
    }

    public static function listLeads(Db $db, array $actor, array $query): array
    {
        actor_require($actor, 'leads.view');
        [$scope, $params] = self::leadScope($actor);
        $sql = 'FROM leads l
            LEFT JOIN employees e ON e.id = l.manager_id
            LEFT JOIN meal_programs p ON p.id = l.program_id
            WHERE 1=1' . $scope;
        if (!empty($query['stage'])) {
            $sql .= ' AND l.stage = :stage';
            $params['stage'] = (string) $query['stage'];
        }
        if (!empty($query['q'])) {
            $sql .= ' AND (l.name LIKE :q OR l.phone_digits LIKE :digits)';
            $params['q'] = '%' . $query['q'] . '%';
            $params['digits'] = '%' . phone_digits((string) $query['q']) . '%';
        }
        $rows = $db->all(
            'SELECT l.*, e.full_name AS manager_name, p.name AS program_name ' . $sql . ' ORDER BY l.updated_at DESC, l.id DESC',
            $params,
        );
        return ['data' => $rows];
    }

    public static function saveLead(Db $db, array $actor, ?int $id, array $input): array
    {
        actor_require($actor, 'leads.manage');
        if ($id) {
            $before = self::mustLead($db, $actor, $id);
            if (isset($input['stage'])) {
                return self::moveLead($db, $actor, $id, $input);
            }
            $fields = [];
            foreach (['name', 'source', 'comment', 'last_contact_at', 'next_contact_at'] as $field) {
                if (array_key_exists($field, $input)) {
                    $fields[$field] = $input[$field];
                }
            }
            if (isset($input['phone'])) {
                $digits = phone_digits((string) $input['phone']);
                if (strlen($digits) !== 11) {
                    throw new HttpError(422, 'Телефон должен быть в формате +7');
                }
                $fields['phone'] = phone_format($digits);
                $fields['phone_digits'] = $digits;
            }
            if (isset($input['manager_id'])) {
                $fields['manager_id'] = $input['manager_id'] ? (int) $input['manager_id'] : null;
            }
            if (isset($input['program_id'])) {
                $fields['program_id'] = $input['program_id'] ? (int) $input['program_id'] : null;
            }
            if (isset($input['expected_amount'])) {
                $fields['expected_amount'] = money_int($input['expected_amount']);
            }
            if ($fields) {
                $fields['updated_at'] = app_now();
                $sets = [];
                $params = ['id' => $id];
                foreach ($fields as $key => $value) {
                    $sets[] = $key . ' = :' . $key;
                    $params[$key] = $value;
                }
                $db->exec('UPDATE leads SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
                audit_fields($db, $actor['id'], 'leads', $id, $before, $fields);
            }
            return $db->one('SELECT * FROM leads WHERE id = :id', ['id' => $id]);
        }
        $name = trim((string) ($input['name'] ?? ''));
        $digits = phone_digits((string) ($input['phone'] ?? ''));
        if ($name === '' || strlen($digits) !== 11) {
            throw new HttpError(422, 'Укажите имя и телефон лида');
        }
        $newId = $db->insert(
            'INSERT INTO leads (name, phone, phone_digits, manager_id, source, program_id, expected_amount, stage, comment, next_contact_at, created_at, updated_at)
             VALUES (:name, :phone, :digits, :manager, :source, :program, :amount, :stage, :comment, :next, :created, :updated)',
            [
                'name' => $name,
                'phone' => phone_format($digits),
                'digits' => $digits,
                'manager' => (int) ($input['manager_id'] ?? $actor['employee_id'] ?? 0) ?: null,
                'source' => (string) ($input['source'] ?? 'manual'),
                'program' => !empty($input['program_id']) ? (int) $input['program_id'] : null,
                'amount' => money_int($input['expected_amount'] ?? 0),
                'stage' => 'new',
                'comment' => $input['comment'] ?? null,
                'next' => $input['next_contact_at'] ?? app_date_shift(app_today(), 1),
                'created' => app_now(),
                'updated' => app_now(),
            ],
        );
        audit_change($db, $actor['id'], 'leads', $newId, 'create', 'name', null, $name);
        CommService::notifyPermission($db, [], 'leads.view', 'info', 'Новый лид', $name, 'lead', $newId, '/pipeline');
        return $db->one('SELECT * FROM leads WHERE id = :id', ['id' => $newId]);
    }

    public static function moveLead(Db $db, array $actor, int $id, array $input): array
    {
        actor_require($actor, 'leads.manage');
        $stage = (string) ($input['stage'] ?? '');
        if (!in_array($stage, self::STAGES, true)) {
            throw new HttpError(422, 'Неизвестная стадия');
        }
        $lead = self::mustLead($db, $actor, $id);
        $customerId = $lead['customer_id'] ? (int) $lead['customer_id'] : null;
        $db->tx(function (Db $db) use ($actor, $lead, $stage, &$customerId): void {
            if (in_array($stage, ['paid', 'active_client'], true) && !$customerId) {
                $customerId = self::convertLead($db, $actor, $lead);
            }
            $db->exec(
                'UPDATE leads SET stage = :stage, customer_id = :customer, updated_at = :updated WHERE id = :id',
                ['stage' => $stage, 'customer' => $customerId, 'updated' => app_now(), 'id' => $lead['id']],
            );
        });
        audit_change($db, $actor['id'], 'leads', $id, 'move', 'stage', $lead['stage'], $stage);
        $fresh = $db->one('SELECT * FROM leads WHERE id = :id', ['id' => $id]);
        $fresh['customer_id'] = $customerId;
        return $fresh;
    }

    public static function listSubscriptions(Db $db, array $actor, array $query): array
    {
        actor_require($actor, 'subscriptions.view');
        [$scope, $params] = self::scope($actor, 'c');
        $sql = 'FROM subscriptions s
            JOIN customers c ON c.id = s.customer_id
            JOIN meal_programs p ON p.id = s.program_id
            LEFT JOIN employees e ON e.id = s.manager_id
            WHERE 1=1' . $scope;
        if (!empty($query['status'])) {
            $sql .= ' AND s.status = :status';
            $params['status'] = (string) $query['status'];
        }
        $page = clamp_page($query['page'] ?? 1);
        $per = clamp_per_page($query['per_page'] ?? 20);
        $total = (int) $db->one('SELECT COUNT(*) AS c ' . $sql, $params)['c'];
        $rows = $db->all(
            'SELECT s.*, c.full_name, c.phone, p.name AS program_name, e.full_name AS manager_name ' . $sql . ' ORDER BY s.end_date ASC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per),
            $params,
        );
        foreach ($rows as &$row) {
            $row['days_left'] = days_left($row['end_date']);
        }
        return ['data' => $rows, 'page' => $page, 'per_page' => $per, 'total' => $total];
    }

    public static function setSubscriptionState(Db $db, array $actor, int $id, string $state): array
    {
        actor_require($actor, 'subscriptions.manage');
        $sub = $db->one('SELECT * FROM subscriptions WHERE id = :id', ['id' => $id]);
        if (!$sub) {
            throw new HttpError(404, 'Подписка не найдена');
        }
        BillingService::customer($db, $actor, (int) $sub['customer_id']);
        if ($state === 'paused') {
            $db->exec('UPDATE subscriptions SET status = :status WHERE id = :id', ['status' => 'paused', 'id' => $id]);
            $db->exec('UPDATE customers SET status = :status WHERE id = :id', ['status' => 'paused', 'id' => $sub['customer_id']]);
        } elseif ($state === 'active') {
            $left = days_left($sub['end_date']);
            $status = $left < 0 ? 'awaiting_renewal' : ($left <= 3 ? 'ending' : 'active');
            $customer = $left < 0 ? 'renewal_required' : 'active';
            $db->exec('UPDATE subscriptions SET status = :status WHERE id = :id', ['status' => $status, 'id' => $id]);
            $db->exec('UPDATE customers SET status = :status WHERE id = :id', ['status' => $customer, 'id' => $sub['customer_id']]);
        } elseif ($state === 'cancelled') {
            $db->exec('UPDATE subscriptions SET status = :status WHERE id = :id', ['status' => 'cancelled', 'id' => $id]);
        } else {
            throw new HttpError(422, 'Неизвестный статус');
        }
        audit_change($db, $actor['id'], 'subscriptions', $id, $state, 'status', $sub['status'], $state);
        $fresh = $db->one('SELECT * FROM subscriptions WHERE id = :id', ['id' => $id]);
        $fresh['days_left'] = days_left($fresh['end_date']);
        return $fresh;
    }

    private static function convertLead(Db $db, array $actor, array $lead): int
    {
        $program = $lead['program_id']
            ? $db->one('SELECT * FROM meal_programs WHERE id = :id', ['id' => $lead['program_id']])
            : $db->one('SELECT * FROM meal_programs WHERE available = 1 ORDER BY id LIMIT 1');
        if (!$program) {
            throw new HttpError(422, 'Нет программы питания для подписки');
        }
        $today = app_today();
        $customerId = $db->insert(
            'INSERT INTO customers (full_name, phone, phone_digits, whatsapp, status, vip, manager_id, source, city, district, comment, first_contact_at, first_payment_at, last_payment_at, last_contact_at, registered_at, created_at, name_key)
             VALUES (:name, :phone, :digits, :whatsapp, :status, 0, :manager, :source, :city, :district, :comment, :c1, :c2, :c3, :c4, :c5, :created, :name_key)',
            [
                'name' => $lead['name'],
                'phone' => $lead['phone'],
                'digits' => $lead['phone_digits'],
                'whatsapp' => $lead['phone'],
                'status' => 'active',
                'manager' => $lead['manager_id'],
                'source' => $lead['source'],
                'city' => 'Алматы',
                'district' => '',
                'comment' => $lead['comment'],
                'c1' => $today,
                'c2' => $today,
                'c3' => $today,
                'c4' => $today,
                'c5' => $today,
                'created' => app_now(),
                'name_key' => name_key((string) $lead['name']),
            ],
        );
        self::saveAddress($db, $customerId, ['city' => 'Алматы', 'district' => '', 'street' => '', 'building' => '']);
        $end = app_date_shift($today, (int) $program['days'] - 1);
        $subId = $db->insert(
            'INSERT INTO subscriptions (customer_id, program_id, days, start_date, end_date, price, discount_amount, total_amount, payment_method, payment_status, status, manager_id, created_at)
             VALUES (:customer, :program, :days, :start, :end, :price, 0, :price, :method, :pay, :status, :manager, :created)',
            [
                'customer' => $customerId,
                'program' => $program['id'],
                'days' => $program['days'],
                'start' => $today,
                'end' => $end,
                'price' => $program['price'],
                'method' => 'kaspi',
                'pay' => 'paid',
                'status' => 'active',
                'manager' => $lead['manager_id'],
                'created' => app_now(),
            ],
        );
        $db->insert(
            'INSERT INTO subscription_items (subscription_id, title, calories, meals_per_day, price) VALUES (:sub, :title, :cal, :meals, :price)',
            ['sub' => $subId, 'title' => $program['name'], 'cal' => $program['calories'], 'meals' => $program['meals_per_day'], 'price' => $program['price']],
        );
        $amount = (int) ($lead['expected_amount'] ?: $program['price']);
        $orderNo = (int) $db->one('SELECT COUNT(*) AS c FROM orders')['c'] + 1;
        $payNo = (int) $db->one('SELECT COUNT(*) AS c FROM payments')['c'] + 1;
        $orderId = $db->insert(
            'INSERT INTO orders (number, customer_id, subscription_id, amount, status, created_at) VALUES (:number, :customer, :sub, :amount, :status, :created)',
            ['number' => 'FF-' . str_pad((string) $orderNo, 4, '0', STR_PAD_LEFT), 'customer' => $customerId, 'sub' => $subId, 'amount' => $amount, 'status' => 'paid', 'created' => app_now()],
        );
        $db->insert(
            'INSERT INTO payments (number, customer_id, subscription_id, order_id, amount, method, status, manager_id, paid_at, created_at)
             VALUES (:number, :customer, :sub, :order_id, :amount, :method, :status, :manager, :paid, :created)',
            [
                'number' => 'PAY-' . str_pad((string) $payNo, 4, '0', STR_PAD_LEFT),
                'customer' => $customerId,
                'sub' => $subId,
                'order_id' => $orderId,
                'amount' => $amount,
                'method' => 'kaspi',
                'status' => 'paid',
                'manager' => $lead['manager_id'],
                'paid' => app_now(),
                'created' => app_now(),
            ],
        );
        audit_change($db, $actor['id'], 'customers', $customerId, 'convert', 'lead_id', null, $lead['id']);
        return $customerId;
    }

    private static function saveAddress(Db $db, int $customerId, array $address): void
    {
        $db->exec('UPDATE customer_addresses SET is_primary = 0 WHERE customer_id = :id', ['id' => $customerId]);
        $existing = $db->one('SELECT id FROM customer_addresses WHERE customer_id = :id ORDER BY id DESC LIMIT 1', ['id' => $customerId]);
        $payload = [
            'city' => (string) ($address['city'] ?? 'Алматы'),
            'district' => (string) ($address['district'] ?? ''),
            'street' => (string) ($address['street'] ?? ''),
            'building' => (string) ($address['building'] ?? ''),
            'apartment' => $address['apartment'] ?? null,
            'comment' => $address['comment'] ?? null,
            'customer' => $customerId,
        ];
        if ($existing) {
            $payload['id'] = $existing['id'];
            $db->exec(
                'UPDATE customer_addresses SET city=:city, district=:district, street=:street, building=:building, apartment=:apartment, comment=:comment, is_primary=1 WHERE id=:id',
                $payload,
            );
            return;
        }
        $db->insert(
            'INSERT INTO customer_addresses (customer_id, city, district, street, building, apartment, comment, is_primary)
             VALUES (:customer, :city, :district, :street, :building, :apartment, :comment, 1)',
            $payload,
        );
    }

    private static function saveSchedule(Db $db, int $customerId, array $schedule): void
    {
        $days = array_map('intval', $schedule['days'] ?? []);
        $slot = (string) ($schedule['slot'] ?? 'morning');
        if (!in_array($slot, ['morning', 'day', 'evening', 'custom'], true)) {
            throw new HttpError(422, 'Неизвестный слот доставки');
        }
        $address = $db->one('SELECT id FROM customer_addresses WHERE customer_id = :id AND is_primary = 1', ['id' => $customerId]);
        $db->exec('DELETE FROM delivery_schedule_days WHERE customer_id = :id', ['id' => $customerId]);
        foreach ($days as $day) {
            if ($day < 1 || $day > 7) {
                continue;
            }
            $db->insert(
                'INSERT INTO delivery_schedule_days (customer_id, weekday, slot, time_from, time_to, address_id)
                 VALUES (:customer, :day, :slot, :from, :to, :address)',
                [
                    'customer' => $customerId,
                    'day' => $day,
                    'slot' => $slot,
                    'from' => $schedule['time_from'] ?? null,
                    'to' => $schedule['time_to'] ?? null,
                    'address' => $address['id'] ?? null,
                ],
            );
        }
    }

    private static function timeline(Db $db, int $customerId): array
    {
        $events = [];
        $customer = $db->one('SELECT registered_at, created_at FROM customers WHERE id = :id', ['id' => $customerId]);
        $events[] = ['at' => $customer['created_at'], 'text' => 'Клиент создан в CRM'];
        foreach ($db->all('SELECT paid_at, created_at, amount, status FROM payments WHERE customer_id = :id', ['id' => $customerId]) as $row) {
            $events[] = ['at' => $row['paid_at'] ?: $row['created_at'], 'text' => 'Оплата ' . number_format((int) $row['amount'], 0, '.', ' ') . ' ₸ · ' . $row['status']];
        }
        foreach ($db->all('SELECT created_at, status, total_amount FROM subscriptions WHERE customer_id = :id', ['id' => $customerId]) as $row) {
            $events[] = ['at' => $row['created_at'], 'text' => 'Подписка ' . number_format((int) $row['total_amount'], 0, '.', ' ') . ' ₸ · ' . $row['status']];
        }
        foreach ($db->all('SELECT m.created_at, m.body, m.direction FROM whatsapp_messages m JOIN whatsapp_conversations c ON c.id = m.conversation_id WHERE c.customer_id = :id', ['id' => $customerId]) as $row) {
            $events[] = ['at' => $row['created_at'], 'text' => ($row['direction'] === 'in' ? 'Клиент написал' : 'Менеджер написал') . ': ' . mb_substr($row['body'], 0, 80)];
        }
        foreach ($db->all('SELECT created_at, title, status FROM tasks WHERE customer_id = :id', ['id' => $customerId]) as $row) {
            $events[] = ['at' => $row['created_at'], 'text' => 'Задача: ' . $row['title']];
        }
        usort($events, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
        return array_slice($events, 0, 40);
    }

    private static function filterSql(string $filter, array &$params): string
    {
        $today = app_today();
        if (in_array($filter, ['renewal', 'ending_today', 'overdue'], true)) {
            $params['today'] = $today;
        }
        if ($filter === 'renewal') {
            $params['plus7'] = app_date_shift($today, 7);
        }
        if ($filter === 'ending_3') {
            $params['plus3'] = app_date_shift($today, 3);
        }
        if ($filter === 'ending_tomorrow') {
            $params['tomorrow'] = app_date_shift($today, 1);
        }
        return match ($filter) {
            'active' => " AND c.status = 'active'",
            'new' => " AND c.status = 'new'",
            'renewal' => " AND c.status NOT IN ('paused','lost') AND s.end_date >= :today AND s.end_date <= :plus7",
            'ending_3' => " AND c.status NOT IN ('paused','lost') AND s.end_date = :plus3",
            'ending_tomorrow' => " AND c.status NOT IN ('paused','lost') AND s.end_date = :tomorrow",
            'ending_today' => " AND c.status NOT IN ('paused','lost') AND s.end_date = :today",
            'overdue' => " AND c.status NOT IN ('paused','lost') AND s.end_date < :today AND s.status != 'cancelled'",
            'paused' => " AND c.status = 'paused'",
            'lost' => " AND c.status = 'lost'",
            'vip' => ' AND c.vip = 1',
            default => '',
        };
    }

    private static function scope(array $actor, string $alias): array
    {
        if (actor_can($actor, 'customers.view_all') || actor_can($actor, 'finance.view')) {
            return ['', []];
        }
        return [" AND {$alias}.manager_id = :scope_manager", ['scope_manager' => $actor['employee_id']]];
    }

    private static function leadScope(array $actor): array
    {
        if (actor_can($actor, 'leads.view_all') || actor_can($actor, 'customers.view_all')) {
            return ['', []];
        }
        return [' AND l.manager_id = :scope_manager', ['scope_manager' => $actor['employee_id']]];
    }

    private static function mustLead(Db $db, array $actor, int $id): array
    {
        $lead = $db->one('SELECT * FROM leads WHERE id = :id', ['id' => $id]);
        if (!$lead) {
            throw new HttpError(404, 'Лид не найден');
        }
        if (!actor_can($actor, 'leads.view_all') && !actor_can($actor, 'customers.view_all')) {
            if ((int) $lead['manager_id'] !== (int) $actor['employee_id']) {
                throw new HttpError(403, 'Недостаточно прав', 'forbidden');
            }
        }
        return $lead;
    }
}
