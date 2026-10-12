<?php
declare(strict_types=1);

final class OpsService
{
    public static function programs(Db $db, array $actor): array
    {
        actor_require($actor, 'programs.view');
        return ['data' => $db->all('SELECT * FROM meal_programs ORDER BY id')];
    }

    public static function saveProgram(Db $db, array $actor, ?int $id, array $input): array
    {
        actor_require($actor, 'programs.manage');
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '') {
            throw new HttpError(422, 'Укажите название программы');
        }
        $payload = [
            'name' => $name,
            'slug' => strtolower(trim((string) ($input['slug'] ?? preg_replace('/[^a-z0-9]+/i', '-', $name)))),
            'description' => (string) ($input['description'] ?? ''),
            'calories' => (int) ($input['calories'] ?? 0),
            'price' => money_int($input['price'] ?? 0),
            'days' => max(1, (int) ($input['days'] ?? 1)),
            'meals' => max(1, (int) ($input['meals_per_day'] ?? 5)),
            'available' => !isset($input['available']) || !empty($input['available']) ? 1 : 0,
            'image' => $input['image_url'] ?? null,
        ];
        if ($id) {
            $before = $db->one('SELECT * FROM meal_programs WHERE id = :id', ['id' => $id]);
            if (!$before) {
                throw new HttpError(404, 'Программа не найдена');
            }
            $payload['id'] = $id;
            $db->exec(
                'UPDATE meal_programs SET name=:name, slug=:slug, description=:description, calories=:calories, price=:price, days=:days, meals_per_day=:meals, available=:available, image_url=:image WHERE id=:id',
                $payload,
            );
            audit_change($db, $actor['id'], 'meal_programs', $id, 'update', 'price', $before['price'], $payload['price']);
        } else {
            $id = $db->insert(
                'INSERT INTO meal_programs (name, slug, description, calories, price, days, meals_per_day, available, image_url, created_at)
                 VALUES (:name, :slug, :description, :calories, :price, :days, :meals, :available, :image, :created)',
                $payload + ['created' => app_now()],
            );
            audit_change($db, $actor['id'], 'meal_programs', $id, 'create', 'name', null, $name);
        }
        return $db->one('SELECT * FROM meal_programs WHERE id = :id', ['id' => $id]);
    }

    public static function inventory(Db $db, array $actor, array $query): array
    {
        actor_require($actor, 'inventory.view');
        $params = [];
        $sql = 'FROM products p
            JOIN product_categories c ON c.id = p.category_id
            LEFT JOIN suppliers s ON s.id = p.supplier_id
            LEFT JOIN inventory i ON i.product_id = p.id
            WHERE 1=1';
        if (!empty($query['low'])) {
            $sql .= ' AND i.quantity < p.min_stock';
        }
        if (!empty($query['q'])) {
            $sql .= ' AND (p.name LIKE :q OR p.sku LIKE :q)';
            $params['q'] = '%' . $query['q'] . '%';
        }
        if (!empty($query['category'])) {
            $sql .= ' AND c.code = :category';
            $params['category'] = (string) $query['category'];
        }
        $page = clamp_page($query['page'] ?? 1);
        $per = clamp_per_page($query['per_page'] ?? 25);
        $total = (int) $db->one('SELECT COUNT(*) AS c ' . $sql, $params)['c'];
        $rows = $db->all(
            'SELECT p.*, c.name AS category_name, s.name AS supplier_name, i.quantity, i.warehouse ' . $sql . ' ORDER BY p.name LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per),
            $params,
        );
        return [
            'data' => $rows,
            'page' => $page,
            'per_page' => $per,
            'total' => $total,
            'categories' => $db->all('SELECT * FROM product_categories ORDER BY id'),
            'suppliers' => $db->all('SELECT * FROM suppliers ORDER BY name'),
        ];
    }

    public static function saveProduct(Db $db, array $actor, array $input): array
    {
        actor_require($actor, 'inventory.manage');
        $name = trim((string) ($input['name'] ?? ''));
        $sku = trim((string) ($input['sku'] ?? ''));
        if ($name === '' || $sku === '') {
            throw new HttpError(422, 'Укажите название и SKU');
        }
        $id = $db->insert(
            'INSERT INTO products (name, sku, category_id, unit, min_stock, purchase_price, avg_price, supplier_id, expires_on, batch, created_at)
             VALUES (:name, :sku, :category, :unit, :min, :purchase, :avg, :supplier, :expires, :batch, :created)',
            [
                'name' => $name,
                'sku' => $sku,
                'category' => (int) ($input['category_id'] ?? 1),
                'unit' => (string) ($input['unit'] ?? 'шт'),
                'min' => (int) ($input['min_stock'] ?? 0),
                'purchase' => money_int($input['purchase_price'] ?? 0),
                'avg' => money_int($input['avg_price'] ?? $input['purchase_price'] ?? 0),
                'supplier' => !empty($input['supplier_id']) ? (int) $input['supplier_id'] : null,
                'expires' => $input['expires_on'] ?? null,
                'batch' => $input['batch'] ?? null,
                'created' => app_now(),
            ],
        );
        $qty = (int) ($input['quantity'] ?? 0);
        $db->insert(
            'INSERT INTO inventory (product_id, warehouse, quantity) VALUES (:product, :warehouse, :qty)',
            ['product' => $id, 'warehouse' => (string) ($input['warehouse'] ?? 'Кухня'), 'qty' => $qty],
        );
        if ($qty > 0) {
            self::writeMove($db, $actor, $id, 'in', $qty, 'Начальный остаток', (string) ($input['warehouse'] ?? 'Кухня'));
        }
        audit_change($db, $actor['id'], 'products', $id, 'create', 'name', null, $name);
        return $db->one('SELECT * FROM products WHERE id = :id', ['id' => $id]);
    }

    public static function moveStock(Db $db, array $config, array $actor, array $input): array
    {
        actor_require($actor, 'inventory.manage');
        $type = (string) ($input['type'] ?? '');
        if (!in_array($type, ['in', 'out', 'writeoff', 'transfer', 'adjust'], true)) {
            throw new HttpError(422, 'Неизвестный тип операции');
        }
        $productId = (int) ($input['product_id'] ?? 0);
        $qty = (int) ($input['quantity'] ?? 0);
        if ($qty <= 0 && $type !== 'adjust') {
            throw new HttpError(422, 'Количество должно быть больше нуля');
        }
        $warehouse = (string) ($input['warehouse'] ?? 'Кухня');
        $stock = $db->one('SELECT * FROM inventory WHERE product_id = :id AND warehouse = :warehouse', ['id' => $productId, 'warehouse' => $warehouse]);
        if (!$stock) {
            throw new HttpError(404, 'Остаток не найден');
        }
        $next = (int) $stock['quantity'];
        if ($type === 'in') {
            $next += $qty;
        } elseif ($type === 'out' || $type === 'writeoff') {
            if ($next < $qty) {
                throw new HttpError(422, 'Недостаточно остатка');
            }
            $next -= $qty;
        } elseif ($type === 'adjust') {
            $next = $qty;
        } elseif ($type === 'transfer') {
            $to = trim((string) ($input['warehouse_to'] ?? ''));
            if ($to === '' || $to === $warehouse) {
                throw new HttpError(422, 'Укажите другой склад');
            }
            if ((int) $stock['quantity'] < $qty) {
                throw new HttpError(422, 'Недостаточно остатка');
            }
            $db->exec('UPDATE inventory SET quantity = quantity - :qty WHERE id = :id', ['qty' => $qty, 'id' => $stock['id']]);
            $dest = $db->one('SELECT * FROM inventory WHERE product_id = :id AND warehouse = :warehouse', ['id' => $productId, 'warehouse' => $to]);
            if ($dest) {
                $db->exec('UPDATE inventory SET quantity = quantity + :qty WHERE id = :id', ['qty' => $qty, 'id' => $dest['id']]);
            } else {
                $db->insert('INSERT INTO inventory (product_id, warehouse, quantity) VALUES (:product, :warehouse, :qty)', [
                    'product' => $productId, 'warehouse' => $to, 'qty' => $qty,
                ]);
            }
            self::writeMove($db, $actor, $productId, 'transfer', $qty, (string) ($input['note'] ?? ('Перемещение на ' . $to)), $warehouse);
            audit_change($db, $actor['id'], 'inventory', $productId, 'transfer', 'quantity', $stock['quantity'], (int) $stock['quantity'] - $qty);
            return ['quantity' => (int) $stock['quantity'] - $qty];
        }
        $db->exec('UPDATE inventory SET quantity = :qty WHERE id = :id', ['qty' => $next, 'id' => $stock['id']]);
        self::writeMove($db, $actor, $productId, $type, $qty, (string) ($input['note'] ?? ''), $warehouse);
        audit_change($db, $actor['id'], 'inventory', $productId, $type, 'quantity', $stock['quantity'], $next);
        $product = $db->one('SELECT * FROM products WHERE id = :id', ['id' => $productId]);
        if ($product && $next < (int) $product['min_stock']) {
            AutomationService::runDaily($db, $config);
        }
        return ['quantity' => $next];
    }

    public static function deliveries(Db $db, array $actor, array $query): array
    {
        self::requireDelivery($actor);
        [$where, $params] = self::deliveryScope($actor);
        $date = (string) ($query['date'] ?? app_today());
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new HttpError(422, 'Некорректная дата');
        }
        $params['date'] = $date;
        $sql = 'FROM deliveries d JOIN customers c ON c.id = d.customer_id WHERE d.delivery_date = :date' . $where;
        if (!empty($query['slot']) && in_array($query['slot'], ['morning', 'day', 'evening'], true)) {
            $sql .= ' AND d.slot = :slot';
            $params['slot'] = $query['slot'];
        }
        if (!empty($query['status'])) {
            $sql .= ' AND d.status = :status';
            $params['status'] = (string) $query['status'];
        }
        $rows = $db->all('SELECT d.*, c.full_name ' . $sql . ' ORDER BY d.slot, d.id', $params);
        return ['data' => $rows, 'date' => $date];
    }

    public static function calendar(Db $db, array $actor, string $month): array
    {
        self::requireDelivery($actor);
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new HttpError(422, 'Некорректный месяц');
        }
        [$where, $params] = self::deliveryScope($actor);
        $params['start'] = $month . '-01';
        $params['end'] = $month . '-31';
        $rows = $db->all(
            'SELECT delivery_date, COUNT(*) AS total FROM deliveries d WHERE delivery_date >= :start AND delivery_date <= :end' . $where . ' GROUP BY delivery_date',
            $params,
        );
        return ['data' => $rows];
    }

    public static function saveDelivery(Db $db, array $actor, ?int $id, array $input): array
    {
        actor_require($actor, 'deliveries.manage');
        $statuses = ['preparing', 'ready', 'handed', 'on_the_way', 'delivered', 'problem', 'cancelled'];
        if ($id) {
            $before = $db->one('SELECT * FROM deliveries WHERE id = :id', ['id' => $id]);
            if (!$before) {
                throw new HttpError(404, 'Доставка не найдена');
            }
            $status = (string) ($input['status'] ?? $before['status']);
            if (!in_array($status, $statuses, true)) {
                throw new HttpError(422, 'Неизвестный статус доставки');
            }
            $db->exec(
                'UPDATE deliveries SET status = :status, comment = :comment, courier_id = :courier, slot = :slot WHERE id = :id',
                [
                    'status' => $status,
                    'comment' => $input['comment'] ?? $before['comment'],
                    'courier' => array_key_exists('courier_id', $input) ? ($input['courier_id'] ?: null) : $before['courier_id'],
                    'slot' => $input['slot'] ?? $before['slot'],
                    'id' => $id,
                ],
            );
            audit_change($db, $actor['id'], 'deliveries', $id, 'update', 'status', $before['status'], $status);
            if ($status === 'problem') {
                CommService::notifyPermission($db, [], 'deliveries.manage', 'critical', 'Проблема с доставкой', (string) $before['address'], 'delivery', $id, '/deliveries?status=problem');
            }
            return $db->one('SELECT * FROM deliveries WHERE id = :id', ['id' => $id]);
        }
        $customerId = (int) ($input['customer_id'] ?? 0);
        BillingService::customer($db, $actor, $customerId);
        $customer = $db->one('SELECT * FROM customers WHERE id = :id', ['id' => $customerId]);
        $newId = $db->insert(
            'INSERT INTO deliveries (customer_id, subscription_id, courier_id, delivery_date, address, phone, program_name, slot, time_from, time_to, comment, status, created_at)
             VALUES (:customer, :sub, :courier, :date, :address, :phone, :program, :slot, :from, :to, :comment, :status, :created)',
            [
                'customer' => $customerId,
                'sub' => $input['subscription_id'] ?? null,
                'courier' => $input['courier_id'] ?? null,
                'date' => $input['delivery_date'] ?? app_today(),
                'address' => (string) ($input['address'] ?? ''),
                'phone' => $customer['phone'],
                'program' => (string) ($input['program_name'] ?? ''),
                'slot' => (string) ($input['slot'] ?? 'morning'),
                'from' => $input['time_from'] ?? null,
                'to' => $input['time_to'] ?? null,
                'comment' => $input['comment'] ?? null,
                'status' => 'preparing',
                'created' => app_now(),
            ],
        );
        return $db->one('SELECT * FROM deliveries WHERE id = :id', ['id' => $newId]);
    }

    private static function writeMove(Db $db, array $actor, int $productId, string $type, int $qty, string $note, string $warehouse): void
    {
        $db->insert(
            'INSERT INTO inventory_transactions (product_id, warehouse, type, quantity, note, employee_id, created_at)
             VALUES (:product, :warehouse, :type, :qty, :note, :employee, :created)',
            [
                'product' => $productId,
                'warehouse' => $warehouse,
                'type' => $type,
                'qty' => $qty,
                'note' => $note,
                'employee' => $actor['employee_id'],
                'created' => app_now(),
            ],
        );
    }

    private static function requireDelivery(array $actor): void
    {
        if (!actor_can($actor, 'deliveries.view') && !actor_can($actor, 'deliveries.own') && !actor_can($actor, 'deliveries.manage')) {
            throw new HttpError(403, 'Недостаточно прав', 'forbidden');
        }
    }

    private static function deliveryScope(array $actor): array
    {
        if (actor_can($actor, 'deliveries.view') || actor_can($actor, 'deliveries.manage')) {
            return ['', []];
        }
        return [' AND d.courier_id = :courier', ['courier' => $actor['employee_id']]];
    }
}
