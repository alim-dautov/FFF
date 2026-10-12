<?php
declare(strict_types=1);

interface PaymentProvider
{
    public function createInvoice(array $payment): array;

    public function createPaymentLink(Db $db, array $config, array $payment): array;

    public function getPaymentStatus(Db $db, array $payment): array;

    public function cancelPayment(Db $db, array $payment): void;

    public function refundPayment(Db $db, array $payment): void;
}

final class ManualPaymentProvider implements PaymentProvider
{
    public function createInvoice(array $payment): array
    {
        return ['provider' => 'manual', 'number' => $payment['number']];
    }

    public function createPaymentLink(Db $db, array $config, array $payment): array
    {
        $token = bin2hex(random_bytes(16));
        $db->insert(
            'INSERT INTO payment_links (payment_id, provider, token, status, expires_at, created_at)
             VALUES (:payment, :provider, :token, :status, :expires, :created)',
            [
                'payment' => $payment['id'],
                'provider' => 'manual',
                'token' => $token,
                'status' => 'active',
                'expires' => (new DateTimeImmutable('+14 days'))->format('Y-m-d H:i:s'),
                'created' => app_now(),
            ],
        );
        return [
            'provider' => 'manual',
            'token' => $token,
            'url' => rtrim($config['app_url'], '/') . '/pay/' . $token,
        ];
    }

    public function getPaymentStatus(Db $db, array $payment): array
    {
        return ['status' => $payment['status'], 'provider' => 'manual'];
    }

    public function cancelPayment(Db $db, array $payment): void
    {
        $db->exec('UPDATE payment_links SET status = :status WHERE payment_id = :id', ['status' => 'cancelled', 'id' => $payment['id']]);
    }

    public function refundPayment(Db $db, array $payment): void
    {
        $db->exec('UPDATE payment_links SET status = :status WHERE payment_id = :id', ['status' => 'refunded', 'id' => $payment['id']]);
    }
}

final class KaspiPaymentProvider implements PaymentProvider
{
    public function __construct(private array $config)
    {
        if (($this->config['kaspi_api_key'] ?? '') === '') {
            throw new HttpError(503, 'Kaspi API не настроен. Используется ручная оплата, пока нет ключа.', 'provider_off');
        }
    }

    public function createInvoice(array $payment): array
    {
        throw new HttpError(503, 'Kaspi API не настроен', 'provider_off');
    }

    public function createPaymentLink(Db $db, array $config, array $payment): array
    {
        throw new HttpError(503, 'Kaspi API не настроен', 'provider_off');
    }

    public function getPaymentStatus(Db $db, array $payment): array
    {
        throw new HttpError(503, 'Kaspi API не настроен', 'provider_off');
    }

    public function cancelPayment(Db $db, array $payment): void
    {
        throw new HttpError(503, 'Kaspi API не настроен', 'provider_off');
    }

    public function refundPayment(Db $db, array $payment): void
    {
        throw new HttpError(503, 'Kaspi API не настроен', 'provider_off');
    }
}

final class BillingService
{
    public static function provider(array $config): PaymentProvider
    {
        if (($config['payment_provider'] ?? 'manual') === 'kaspi') {
            return new KaspiPaymentProvider($config);
        }
        return new ManualPaymentProvider();
    }

    public static function create(Db $db, array $config, array $actor, array $input): array
    {
        actor_require($actor, 'payments.manage');
        $customerId = (int) ($input['customer_id'] ?? 0);
        $customer = self::customer($db, $actor, $customerId);
        $subscriptionId = (int) ($input['subscription_id'] ?? 0);
        $sub = $subscriptionId
            ? $db->one('SELECT * FROM subscriptions WHERE id = :id AND customer_id = :customer', ['id' => $subscriptionId, 'customer' => $customerId])
            : $db->one('SELECT * FROM subscriptions WHERE customer_id = :customer ORDER BY id DESC LIMIT 1', ['customer' => $customerId]);
        if (!$sub) {
            throw new HttpError(422, 'У клиента нет подписки для счёта');
        }
        $amount = isset($input['amount']) ? money_int($input['amount']) : (int) $sub['total_amount'];
        if ($amount <= 0) {
            throw new HttpError(422, 'Сумма должна быть больше нуля');
        }
        $method = (string) ($input['method'] ?? 'kaspi');
        return $db->tx(function (Db $db) use ($config, $actor, $customer, $sub, $amount, $method): array {
            $orderId = $db->insert(
                'INSERT INTO orders (number, customer_id, subscription_id, amount, status, created_at)
                 VALUES (:number, :customer, :sub, :amount, :status, :created)',
                [
                    'number' => self::nextNumber($db, 'orders', 'FF-'),
                    'customer' => $customer['id'],
                    'sub' => $sub['id'],
                    'amount' => $amount,
                    'status' => 'pending',
                    'created' => app_now(),
                ],
            );
            $paymentId = $db->insert(
                'INSERT INTO payments (number, customer_id, subscription_id, order_id, amount, method, status, manager_id, created_at)
                 VALUES (:number, :customer, :sub, :order_id, :amount, :method, :status, :manager, :created)',
                [
                    'number' => self::nextNumber($db, 'payments', 'PAY-'),
                    'customer' => $customer['id'],
                    'sub' => $sub['id'],
                    'order_id' => $orderId,
                    'amount' => $amount,
                    'method' => $method,
                    'status' => 'pending',
                    'manager' => $actor['employee_id'],
                    'created' => app_now(),
                ],
            );
            $payment = $db->one('SELECT * FROM payments WHERE id = :id', ['id' => $paymentId]);
            $provider = self::provider($config);
            $provider->createInvoice($payment);
            $link = $provider->createPaymentLink($db, $config, $payment);
            audit_change($db, $actor['id'], 'payments', $paymentId, 'create', 'amount', null, $amount);
            return ['payment' => $payment, 'link' => $link];
        });
    }

    public static function markPaid(Db $db, array $config, array $actor, int $id): array
    {
        actor_require($actor, 'payments.manage');
        $payment = $db->one('SELECT * FROM payments WHERE id = :id', ['id' => $id]);
        if (!$payment) {
            throw new HttpError(404, 'Платёж не найден');
        }
        self::customer($db, $actor, (int) $payment['customer_id']);
        if ($payment['status'] === 'paid') {
            return $payment;
        }
        if ($payment['status'] !== 'pending') {
            throw new HttpError(422, 'Этот платёж нельзя отметить оплаченным');
        }
        return self::applyPaid($db, $config, $actor['id'], $payment, $actor['employee_id']);
    }

    public static function applyPaid(Db $db, array $config, ?int $userId, array $payment, ?int $managerId): array
    {
        return $db->tx(function (Db $db) use ($config, $userId, $payment, $managerId): array {
            $now = app_now();
            $db->exec('UPDATE payments SET status = :status, paid_at = :paid WHERE id = :id', [
                'status' => 'paid',
                'paid' => $now,
                'id' => $payment['id'],
            ]);
            if ($payment['order_id']) {
                $db->exec('UPDATE orders SET status = :status WHERE id = :id', ['status' => 'paid', 'id' => $payment['order_id']]);
            }
            $db->exec('UPDATE payment_links SET status = :status WHERE payment_id = :id', ['status' => 'paid', 'id' => $payment['id']]);
            $sub = $payment['subscription_id']
                ? $db->one('SELECT * FROM subscriptions WHERE id = :id', ['id' => $payment['subscription_id']])
                : null;
            if ($sub) {
                $today = app_today();
                $newEnd = $sub['end_date'] < $today
                    ? app_date_shift($today, (int) $sub['days'] - 1)
                    : app_date_shift($sub['end_date'], (int) $sub['days']);
                $db->exec(
                    'UPDATE subscriptions SET status = :status, payment_status = :pay, payment_method = :method, end_date = :end WHERE id = :id',
                    ['status' => 'active', 'pay' => 'paid', 'method' => $payment['method'], 'end' => $newEnd, 'id' => $sub['id']],
                );
                $db->exec(
                    'UPDATE discounts SET renewed_after = 1 WHERE subscription_id = :id AND renewed_after = 0',
                    ['id' => $sub['id']],
                );
            }
            $customer = $db->one('SELECT * FROM customers WHERE id = :id', ['id' => $payment['customer_id']]);
            $db->exec(
                'UPDATE customers SET status = :status, last_payment_at = :paid, first_payment_at = COALESCE(first_payment_at, :paid) WHERE id = :id',
                ['status' => 'active', 'paid' => app_today(), 'id' => $customer['id']],
            );
            audit_change($db, $userId, 'payments', (int) $payment['id'], 'paid', 'status', $payment['status'], 'paid');
            $manager = $managerId ?: ($customer['manager_id'] ? (int) $customer['manager_id'] : null);
            CommService::notifyEmployee(
                $db,
                $config,
                $manager,
                'important',
                'Оплата получена',
                $customer['full_name'] . ' оплатил ' . number_format((int) $payment['amount'], 0, '.', ' ') . ' ₸',
                'payment',
                (int) $payment['id'],
                '/customers/' . $customer['id'],
            );
            AutomationService::emit($db, $config, 'payment_received', [
                'entity_id' => (int) $payment['id'],
                'entity_type' => 'payment',
                'employee_id' => $manager,
                'title' => 'Оплата получена',
                'body' => $customer['full_name'],
                'link' => '/customers/' . $customer['id'],
            ]);
            return $db->one('SELECT * FROM payments WHERE id = :id', ['id' => $payment['id']]);
        });
    }

    public static function cancel(Db $db, array $config, array $actor, int $id): array
    {
        actor_require($actor, 'payments.manage');
        $payment = self::must($db, $id);
        self::customer($db, $actor, (int) $payment['customer_id']);
        if ($payment['status'] !== 'pending') {
            throw new HttpError(422, 'Отменить можно только ожидающий платёж');
        }
        self::provider($config)->cancelPayment($db, $payment);
        $db->exec('UPDATE payments SET status = :status WHERE id = :id', ['status' => 'cancelled', 'id' => $id]);
        if ($payment['order_id']) {
            $db->exec('UPDATE orders SET status = :status WHERE id = :id', ['status' => 'cancelled', 'id' => $payment['order_id']]);
        }
        audit_change($db, $actor['id'], 'payments', $id, 'cancel', 'status', 'pending', 'cancelled');
        return $db->one('SELECT * FROM payments WHERE id = :id', ['id' => $id]);
    }

    public static function refund(Db $db, array $config, array $actor, int $id): array
    {
        actor_require($actor, 'payments.manage');
        $payment = self::must($db, $id);
        self::customer($db, $actor, (int) $payment['customer_id']);
        if ($payment['status'] !== 'paid') {
            throw new HttpError(422, 'Вернуть можно только оплаченный платёж');
        }
        self::provider($config)->refundPayment($db, $payment);
        $db->exec('UPDATE payments SET status = :status WHERE id = :id', ['status' => 'refunded', 'id' => $id]);
        if ($payment['order_id']) {
            $db->exec('UPDATE orders SET status = :status WHERE id = :id', ['status' => 'refunded', 'id' => $payment['order_id']]);
        }
        audit_change($db, $actor['id'], 'payments', $id, 'refund', 'status', 'paid', 'refunded');
        CommService::notifyPermission($db, $config, 'finance.view', 'important', 'Возврат', 'Платёж ' . $payment['number'] . ' возвращён', 'payment', $id, '/finance');
        return $db->one('SELECT * FROM payments WHERE id = :id', ['id' => $id]);
    }

    public static function publicStatus(Db $db, string $token): array
    {
        $link = $db->one('SELECT * FROM payment_links WHERE token = :token', ['token' => $token]);
        if (!$link) {
            throw new HttpError(404, 'Ссылка на оплату не найдена');
        }
        $payment = $db->one('SELECT id, number, amount, status, method, created_at FROM payments WHERE id = :id', ['id' => $link['payment_id']]);
        $customer = $db->one(
            'SELECT c.full_name FROM payments p JOIN customers c ON c.id = p.customer_id WHERE p.id = :id',
            ['id' => $link['payment_id']],
        );
        return [
            'number' => $payment['number'],
            'amount' => (int) $payment['amount'],
            'status' => $payment['status'],
            'method' => $payment['method'],
            'customer' => $customer['full_name'] ?? '',
            'provider' => $link['provider'],
        ];
    }

    public static function webhook(Db $db, array $config, string $provider, string $raw, string $signature): array
    {
        if ($provider !== 'kaspi') {
            throw new HttpError(404, 'Провайдер не найден');
        }
        $secret = $config['kaspi_webhook_secret'] ?? '';
        if ($secret === '' || ($config['kaspi_api_key'] ?? '') === '') {
            throw new HttpError(503, 'Webhook Kaspi не настроен', 'provider_off');
        }
        $expected = hash_hmac('sha256', $raw, $secret);
        if (!hash_equals($expected, $signature)) {
            throw new HttpError(401, 'Неверная подпись', 'unauthorized');
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['payment_number'])) {
            throw new HttpError(422, 'Некорректное тело webhook');
        }
        $payment = $db->one('SELECT * FROM payments WHERE number = :number', ['number' => $data['payment_number']]);
        if (!$payment) {
            throw new HttpError(404, 'Платёж не найден');
        }
        $status = (string) ($data['status'] ?? '');
        if ($status === 'paid') {
            return self::applyPaid($db, $config, null, $payment, $payment['manager_id'] ? (int) $payment['manager_id'] : null);
        }
        if (in_array($status, ['failed', 'cancelled'], true) && $payment['status'] === 'pending') {
            $db->exec('UPDATE payments SET status = :status WHERE id = :id', ['status' => $status, 'id' => $payment['id']]);
        }
        return ['ok' => true];
    }

    public static function listPayments(Db $db, array $actor, array $query): array
    {
        actor_require($actor, 'payments.view');
        [$where, $params] = self::scope($actor, 'c');
        $page = clamp_page($query['page'] ?? 1);
        $per = clamp_per_page($query['per_page'] ?? 20);
        $sql = 'FROM payments p JOIN customers c ON c.id = p.customer_id LEFT JOIN employees e ON e.id = p.manager_id WHERE 1=1' . $where;
        if (!empty($query['customer_id'])) {
            $sql .= ' AND p.customer_id = :customer';
            $params['customer'] = (int) $query['customer_id'];
        }
        if (!empty($query['status'])) {
            $sql .= ' AND p.status = :status';
            $params['status'] = (string) $query['status'];
        }
        $total = (int) $db->one('SELECT COUNT(*) AS c ' . $sql, $params)['c'];
        $rows = $db->all(
            'SELECT p.*, c.full_name, e.full_name AS manager_name ' . $sql . ' ORDER BY p.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per),
            $params,
        );
        return ['data' => $rows, 'page' => $page, 'per_page' => $per, 'total' => $total];
    }

    public static function listOrders(Db $db, array $actor, array $query): array
    {
        if (!actor_can($actor, 'payments.view') && !actor_can($actor, 'customers.view')) {
            throw new HttpError(403, 'Недостаточно прав', 'forbidden');
        }
        [$where, $params] = self::scope($actor, 'c');
        $page = clamp_page($query['page'] ?? 1);
        $per = clamp_per_page($query['per_page'] ?? 20);
        $sql = 'FROM orders o JOIN customers c ON c.id = o.customer_id WHERE 1=1' . $where;
        if (!empty($query['status'])) {
            $sql .= ' AND o.status = :status';
            $params['status'] = (string) $query['status'];
        }
        $total = (int) $db->one('SELECT COUNT(*) AS c ' . $sql, $params)['c'];
        $rows = $db->all(
            'SELECT o.*, c.full_name, c.phone ' . $sql . ' ORDER BY o.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per),
            $params,
        );
        return ['data' => $rows, 'page' => $page, 'per_page' => $per, 'total' => $total];
    }

    public static function customer(Db $db, array $actor, int $id): array
    {
        $row = $db->one('SELECT * FROM customers WHERE id = :id', ['id' => $id]);
        if (!$row) {
            throw new HttpError(404, 'Клиент не найден');
        }
        if (!actor_can($actor, 'customers.view_all') && !actor_can($actor, 'finance.view')) {
            if ((int) $row['manager_id'] !== (int) $actor['employee_id']) {
                throw new HttpError(403, 'Недостаточно прав', 'forbidden');
            }
        }
        return $row;
    }

    private static function must(Db $db, int $id): array
    {
        $payment = $db->one('SELECT * FROM payments WHERE id = :id', ['id' => $id]);
        if (!$payment) {
            throw new HttpError(404, 'Платёж не найден');
        }
        return $payment;
    }

    private static function scope(array $actor, string $alias): array
    {
        if (actor_can($actor, 'customers.view_all') || actor_can($actor, 'finance.view') || actor_can($actor, 'analytics.view_all')) {
            return ['', []];
        }
        return [" AND {$alias}.manager_id = :scope_manager", ['scope_manager' => $actor['employee_id']]];
    }

    private static function nextNumber(Db $db, string $table, string $prefix): string
    {
        $count = (int) $db->one('SELECT COUNT(*) AS c FROM ' . $table)['c'];
        return $prefix . str_pad((string) ($count + 1), 4, '0', STR_PAD_LEFT);
    }
}
