<?php
declare(strict_types=1);

final class Schema
{
    public static function install(Db $db): void
    {
        $db->pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version INTEGER PRIMARY KEY)');
        $v1 = $db->one('SELECT version FROM schema_migrations WHERE version = 1');
        if (!$v1) {
            foreach (self::statements($db->driver) as $sql) {
                $db->pdo->exec($sql);
            }
            $db->insert('INSERT INTO schema_migrations (version) VALUES (1)');
        }
        $v2 = $db->one('SELECT version FROM schema_migrations WHERE version = 2');
        if (!$v2) {
            self::migrateV2($db);
            $db->insert('INSERT INTO schema_migrations (version) VALUES (2)');
        }
        self::backfillNameKeys($db);
    }

    public static function backfillNameKeys(Db $db): void
    {
        if (!self::hasColumn($db, 'customers', 'name_key')) {
            return;
        }
        $rows = $db->all('SELECT id, full_name FROM customers WHERE name_key IS NULL OR name_key = \'\'');
        foreach ($rows as $row) {
            $db->exec('UPDATE customers SET name_key = :key WHERE id = :id', [
                'key' => name_key((string) $row['full_name']),
                'id' => $row['id'],
            ]);
        }
    }

    public static function migrateV2(Db $db): void
    {
        $driver = $db->driver;
        self::addColumnIfMissing($db, 'customers', 'name_key', $driver === 'mysql' ? 'VARCHAR(190) NULL' : 'TEXT NULL');
        self::ensureIndex($db, 'customers', 'idx_customers_name_key', ['name_key']);

        $db->pdo->exec(self::createTable($driver, 'client_subscriptions', [
            'cols' => [
                'id' => 'pk',
                'customer_id' => 'int',
                'full_name' => 'string:160',
                'line' => 'string:32',
                'section' => 'string:120 null',
                'tariff_days' => 'int null',
                'pack_days' => 'string:64 null',
                'kcal' => 'string:80 null',
                'ration_no' => 'string:64 null',
                'diet' => 'string:120 null',
                'delivery_time' => 'string:16 null',
                'period_start' => 'date null',
                'period_end' => 'date null',
                'end_date' => 'date null',
                'pauses' => 'text null',
                'status' => 'string:16',
                'tariff_days_ref' => 'int null',
                'flag_mark' => 'string:5 null',
                'notes' => 'text null',
                'source_row' => 'int null',
                'source_text' => 'text null',
                'needs_review' => 'bool',
                'review_reasons' => 'text null',
                'import_batch_id' => 'int null',
                'created_at' => 'datetime',
                'updated_at' => 'datetime',
            ],
            'fks' => ['customer_id' => 'customers.id CASCADE'],
            'index' => [['customer_id'], ['status'], ['line'], ['end_date'], ['import_batch_id'], ['needs_review']],
        ]));
        foreach ([['customer_id'], ['status'], ['line'], ['end_date'], ['import_batch_id'], ['needs_review']] as $cols) {
            $idx = 'idx_client_subscriptions_' . implode('_', $cols);
            self::ensureIndex($db, 'client_subscriptions', $idx, $cols);
        }

        $db->pdo->exec(self::createTable($driver, 'import_batches', [
            'cols' => [
                'id' => 'pk',
                'user_id' => 'int null',
                'filename' => 'string:255',
                'stored_path' => 'string:255 null',
                'status' => 'string:32',
                'total_rows' => 'int',
                'created_customers' => 'int',
                'created_subscriptions' => 'int',
                'updated_rows' => 'int',
                'skipped_rows' => 'int',
                'error_rows' => 'int',
                'report_json' => 'text null',
                'created_at' => 'datetime',
                'rolled_back_at' => 'datetime null',
            ],
            'fks' => ['user_id' => 'users.id SET NULL'],
        ]));

        $db->pdo->exec(self::createTable($driver, 'import_changes', [
            'cols' => [
                'id' => 'pk',
                'batch_id' => 'int',
                'entity_type' => 'string:40',
                'entity_id' => 'int',
                'action' => 'string:32',
                'before_json' => 'text null',
                'created_at' => 'datetime',
            ],
            'fks' => ['batch_id' => 'import_batches.id CASCADE'],
            'index' => [['batch_id']],
        ]));
        self::ensureIndex($db, 'import_changes', 'idx_import_changes_batch_id', ['batch_id']);

        $customers = $db->all('SELECT id, full_name FROM customers');
        foreach ($customers as $row) {
            $key = name_key((string) $row['full_name']);
            $db->exec('UPDATE customers SET name_key = :key WHERE id = :id', ['key' => $key, 'id' => $row['id']]);
        }

        self::ensurePermission($db, 'customers.import', 'Импорт клиентов');
        foreach (['admin', 'super_admin'] as $roleCode) {
            $role = $db->one('SELECT id FROM roles WHERE code = :code', ['code' => $roleCode]);
            $perm = $db->one('SELECT id FROM permissions WHERE code = :code', ['code' => 'customers.import']);
            if ($role && $perm) {
                $exists = $db->one(
                    'SELECT role_id FROM role_permissions WHERE role_id = :role AND permission_id = :perm',
                    ['role' => $role['id'], 'perm' => $perm['id']],
                );
                if (!$exists) {
                    $db->insert(
                        'INSERT INTO role_permissions (role_id, permission_id) VALUES (:role, :perm)',
                        ['role' => $role['id'], 'perm' => $perm['id']],
                    );
                }
            }
        }
    }

    private static function ensurePermission(Db $db, string $code, string $name): void
    {
        $row = $db->one('SELECT id FROM permissions WHERE code = :code', ['code' => $code]);
        if (!$row) {
            $db->insert('INSERT INTO permissions (code, name) VALUES (:code, :name)', ['code' => $code, 'name' => $name]);
        }
    }

    private static function addColumnIfMissing(Db $db, string $table, string $column, string $ddl): void
    {
        if (self::hasColumn($db, $table, $column)) {
            return;
        }
        $q = self::qid($db->driver, $table);
        $db->pdo->exec('ALTER TABLE ' . $q . ' ADD COLUMN ' . self::qid($db->driver, $column) . ' ' . $ddl);
    }

    private static function hasColumn(Db $db, string $table, string $column): bool
    {
        if ($db->driver === 'mysql') {
            $row = $db->one(
                'SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c',
                ['t' => $table, 'c' => $column],
            );
            return (bool) $row;
        }
        $cols = $db->all('PRAGMA table_info(' . self::qid('sqlite', $table) . ')');
        foreach ($cols as $col) {
            if (($col['name'] ?? '') === $column) {
                return true;
            }
        }
        return false;
    }

    private static function ensureIndex(Db $db, string $table, string $name, array $cols): void
    {
        $quoted = array_map(fn (string $c) => self::qid($db->driver, $c), $cols);
        $sql = 'CREATE INDEX IF NOT EXISTS ' . self::qid($db->driver, $name)
            . ' ON ' . self::qid($db->driver, $table) . ' (' . implode(', ', $quoted) . ')';
        $db->pdo->exec($sql);
    }

    public static function statements(string $driver): array
    {
        $sql = [];
        foreach (self::tables() as $name => $def) {
            $sql[] = self::createTable($driver, $name, $def);
        }
        foreach (self::tables() as $name => $def) {
            foreach ($def['index'] ?? [] as $cols) {
                $idx = 'idx_' . $name . '_' . implode('_', $cols);
                $quoted = array_map(fn (string $c) => self::qid($driver, $c), $cols);
                $sql[] = 'CREATE INDEX ' . self::qid($driver, $idx) . ' ON ' . self::qid($driver, $name) . ' (' . implode(', ', $quoted) . ')';
            }
        }
        return $sql;
    }

    private static function tables(): array
    {
        return [
            'roles' => [
                'cols' => ['id' => 'pk', 'code' => 'string:64', 'name' => 'string:120'],
                'unique' => [['code']],
            ],
            'permissions' => [
                'cols' => ['id' => 'pk', 'code' => 'string:80', 'name' => 'string:160'],
                'unique' => [['code']],
            ],
            'role_permissions' => [
                'cols' => ['role_id' => 'int', 'permission_id' => 'int'],
                'pk' => ['role_id', 'permission_id'],
                'fks' => ['role_id' => 'roles.id CASCADE', 'permission_id' => 'permissions.id CASCADE'],
            ],
            'employees' => [
                'cols' => [
                    'id' => 'pk', 'full_name' => 'string:160', 'phone' => 'string:32', 'phone_digits' => 'string:20',
                    'email' => 'string:190', 'position' => 'string:120', 'role_id' => 'int', 'department' => 'string:120',
                    'hired_at' => 'date', 'status' => 'string:32', 'salary' => 'money', 'manager_id' => 'int null',
                    'user_id' => 'int null', 'created_at' => 'datetime',
                ],
                'fks' => ['role_id' => 'roles.id RESTRICT', 'manager_id' => 'employees.id SET NULL'],
                'index' => [['email'], ['role_id']],
            ],
            'users' => [
                'cols' => [
                    'id' => 'pk', 'employee_id' => 'int null', 'email' => 'string:190', 'phone' => 'string:32 null',
                    'password_hash' => 'string:255', 'role_id' => 'int', 'status' => 'string:32',
                    'last_login_at' => 'datetime null', 'created_at' => 'datetime',
                ],
                'unique' => [['email']],
                'fks' => ['employee_id' => 'employees.id SET NULL', 'role_id' => 'roles.id RESTRICT'],
            ],
            'sessions' => [
                'cols' => [
                    'id' => 'pk', 'user_id' => 'int', 'token_hash' => 'string:64', 'csrf_token' => 'string:64',
                    'expires_at' => 'datetime', 'revoked_at' => 'datetime null', 'ip' => 'string:64', 'created_at' => 'datetime',
                ],
                'unique' => [['token_hash']],
                'fks' => ['user_id' => 'users.id CASCADE'],
            ],
            'password_resets' => [
                'cols' => [
                    'id' => 'pk', 'user_id' => 'int', 'token_hash' => 'string:64', 'expires_at' => 'datetime',
                    'used_at' => 'datetime null', 'created_at' => 'datetime',
                ],
                'fks' => ['user_id' => 'users.id CASCADE'],
            ],
            'login_attempts' => [
                'cols' => ['id' => 'pk', 'email' => 'string:190', 'ip' => 'string:64', 'success' => 'bool', 'created_at' => 'datetime'],
                'index' => [['email', 'created_at']],
            ],
            'otp_codes' => [
                'cols' => [
                    'id' => 'pk', 'phone' => 'string:20', 'code_hash' => 'string:64', 'expires_at' => 'datetime',
                    'used_at' => 'datetime null', 'created_at' => 'datetime',
                ],
            ],
            'meal_programs' => [
                'cols' => [
                    'id' => 'pk', 'name' => 'string:120', 'slug' => 'string:80', 'description' => 'text',
                    'calories' => 'int', 'price' => 'money', 'days' => 'int', 'meals_per_day' => 'int',
                    'available' => 'bool', 'image_url' => 'string:255 null', 'created_at' => 'datetime',
                ],
                'unique' => [['slug']],
            ],
            'customers' => [
                'cols' => [
                    'id' => 'pk', 'full_name' => 'string:160', 'phone' => 'string:32', 'phone_digits' => 'string:20',
                    'whatsapp' => 'string:32', 'status' => 'string:32', 'vip' => 'bool', 'manager_id' => 'int null',
                    'source' => 'string:64', 'city' => 'string:80', 'district' => 'string:80', 'comment' => 'text null',
                    'first_contact_at' => 'date null', 'first_payment_at' => 'date null', 'last_payment_at' => 'date null',
                    'last_contact_at' => 'date null', 'next_action' => 'string:160 null', 'next_action_at' => 'date null',
                    'registered_at' => 'date', 'created_at' => 'datetime',
                ],
                'fks' => ['manager_id' => 'employees.id SET NULL'],
                'index' => [['phone_digits'], ['status'], ['manager_id']],
            ],
            'customer_addresses' => [
                'cols' => [
                    'id' => 'pk', 'customer_id' => 'int', 'city' => 'string:80', 'district' => 'string:80',
                    'street' => 'string:160', 'building' => 'string:32', 'apartment' => 'string:32 null',
                    'comment' => 'text null', 'is_primary' => 'bool',
                ],
                'fks' => ['customer_id' => 'customers.id CASCADE'],
            ],
            'customer_documents' => [
                'cols' => [
                    'id' => 'pk', 'customer_id' => 'int', 'name' => 'string:180', 'filename' => 'string:180',
                    'mime' => 'string:80', 'size' => 'int', 'created_by' => 'int null', 'created_at' => 'datetime',
                ],
                'fks' => ['customer_id' => 'customers.id CASCADE'],
            ],
            'leads' => [
                'cols' => [
                    'id' => 'pk', 'name' => 'string:160', 'phone' => 'string:32', 'phone_digits' => 'string:20',
                    'manager_id' => 'int null', 'source' => 'string:64', 'program_id' => 'int null',
                    'expected_amount' => 'money', 'stage' => 'string:40', 'comment' => 'text null',
                    'last_contact_at' => 'date null', 'next_contact_at' => 'date null', 'customer_id' => 'int null',
                    'created_at' => 'datetime', 'updated_at' => 'datetime',
                ],
                'fks' => [
                    'manager_id' => 'employees.id SET NULL',
                    'program_id' => 'meal_programs.id SET NULL',
                    'customer_id' => 'customers.id SET NULL',
                ],
                'index' => [['stage'], ['phone_digits'], ['manager_id']],
            ],
            'subscriptions' => [
                'cols' => [
                    'id' => 'pk', 'customer_id' => 'int', 'program_id' => 'int', 'days' => 'int',
                    'start_date' => 'date', 'end_date' => 'date', 'price' => 'money', 'discount_amount' => 'money',
                    'total_amount' => 'money', 'payment_method' => 'string:32 null', 'payment_status' => 'string:32',
                    'status' => 'string:32', 'manager_id' => 'int null', 'comment' => 'text null', 'created_at' => 'datetime',
                ],
                'fks' => [
                    'customer_id' => 'customers.id CASCADE',
                    'program_id' => 'meal_programs.id RESTRICT',
                    'manager_id' => 'employees.id SET NULL',
                ],
                'index' => [['customer_id'], ['end_date'], ['status']],
            ],
            'subscription_items' => [
                'cols' => [
                    'id' => 'pk', 'subscription_id' => 'int', 'title' => 'string:160', 'calories' => 'int',
                    'meals_per_day' => 'int', 'price' => 'money',
                ],
                'fks' => ['subscription_id' => 'subscriptions.id CASCADE'],
            ],
            'orders' => [
                'cols' => [
                    'id' => 'pk', 'number' => 'string:32', 'customer_id' => 'int', 'subscription_id' => 'int null',
                    'amount' => 'money', 'status' => 'string:32', 'comment' => 'text null', 'created_at' => 'datetime',
                ],
                'unique' => [['number']],
                'fks' => ['customer_id' => 'customers.id CASCADE', 'subscription_id' => 'subscriptions.id SET NULL'],
                'index' => [['status'], ['customer_id']],
            ],
            'payments' => [
                'cols' => [
                    'id' => 'pk', 'number' => 'string:32', 'customer_id' => 'int', 'subscription_id' => 'int null',
                    'order_id' => 'int null', 'amount' => 'money', 'method' => 'string:32', 'status' => 'string:32',
                    'manager_id' => 'int null', 'paid_at' => 'datetime null', 'created_at' => 'datetime',
                ],
                'unique' => [['number']],
                'fks' => [
                    'customer_id' => 'customers.id CASCADE',
                    'subscription_id' => 'subscriptions.id SET NULL',
                    'order_id' => 'orders.id SET NULL',
                    'manager_id' => 'employees.id SET NULL',
                ],
                'index' => [['customer_id'], ['status'], ['created_at']],
            ],
            'payment_links' => [
                'cols' => [
                    'id' => 'pk', 'payment_id' => 'int', 'provider' => 'string:32', 'token' => 'string:64',
                    'status' => 'string:32', 'expires_at' => 'datetime null', 'created_at' => 'datetime',
                ],
                'unique' => [['token']],
                'fks' => ['payment_id' => 'payments.id CASCADE'],
            ],
            'discounts' => [
                'cols' => [
                    'id' => 'pk', 'customer_id' => 'int', 'subscription_id' => 'int null', 'percent' => 'int null',
                    'amount' => 'money', 'base_amount' => 'money', 'final_amount' => 'money', 'reason' => 'text null',
                    'given_by' => 'int null', 'renewed_after' => 'bool', 'created_at' => 'datetime',
                ],
                'fks' => [
                    'customer_id' => 'customers.id CASCADE',
                    'subscription_id' => 'subscriptions.id SET NULL',
                    'given_by' => 'employees.id SET NULL',
                ],
            ],
            'delivery_schedule_days' => [
                'cols' => [
                    'id' => 'pk', 'customer_id' => 'int', 'weekday' => 'int', 'slot' => 'string:16',
                    'time_from' => 'string:8 null', 'time_to' => 'string:8 null', 'address_id' => 'int null',
                ],
                'fks' => ['customer_id' => 'customers.id CASCADE', 'address_id' => 'customer_addresses.id SET NULL'],
                'index' => [['customer_id']],
            ],
            'deliveries' => [
                'cols' => [
                    'id' => 'pk', 'customer_id' => 'int', 'subscription_id' => 'int null', 'courier_id' => 'int null',
                    'delivery_date' => 'date', 'address' => 'string:255', 'phone' => 'string:32', 'program_name' => 'string:120',
                    'slot' => 'string:16', 'time_from' => 'string:8 null', 'time_to' => 'string:8 null',
                    'comment' => 'text null', 'status' => 'string:32', 'created_at' => 'datetime',
                ],
                'fks' => [
                    'customer_id' => 'customers.id CASCADE',
                    'subscription_id' => 'subscriptions.id SET NULL',
                    'courier_id' => 'employees.id SET NULL',
                ],
                'index' => [['delivery_date'], ['status'], ['courier_id']],
            ],
            'product_categories' => [
                'cols' => ['id' => 'pk', 'code' => 'string:40', 'name' => 'string:80'],
                'unique' => [['code']],
            ],
            'suppliers' => [
                'cols' => ['id' => 'pk', 'name' => 'string:160', 'phone' => 'string:32 null', 'comment' => 'text null'],
            ],
            'products' => [
                'cols' => [
                    'id' => 'pk', 'name' => 'string:160', 'sku' => 'string:40', 'category_id' => 'int', 'unit' => 'string:16',
                    'min_stock' => 'int', 'purchase_price' => 'money', 'avg_price' => 'money', 'supplier_id' => 'int null',
                    'expires_on' => 'date null', 'batch' => 'string:40 null', 'created_at' => 'datetime',
                ],
                'unique' => [['sku']],
                'fks' => ['category_id' => 'product_categories.id RESTRICT', 'supplier_id' => 'suppliers.id SET NULL'],
            ],
            'inventory' => [
                'cols' => ['id' => 'pk', 'product_id' => 'int', 'warehouse' => 'string:40', 'quantity' => 'int'],
                'unique' => [['product_id', 'warehouse']],
                'fks' => ['product_id' => 'products.id CASCADE'],
            ],
            'inventory_transactions' => [
                'cols' => [
                    'id' => 'pk', 'product_id' => 'int', 'warehouse' => 'string:40', 'type' => 'string:20',
                    'quantity' => 'int', 'note' => 'text null', 'employee_id' => 'int null', 'created_at' => 'datetime',
                ],
                'fks' => ['product_id' => 'products.id CASCADE', 'employee_id' => 'employees.id SET NULL'],
                'index' => [['product_id']],
            ],
            'tasks' => [
                'cols' => [
                    'id' => 'pk', 'title' => 'string:180', 'description' => 'text null', 'assignee_id' => 'int null',
                    'customer_id' => 'int null', 'due_date' => 'date null', 'due_time' => 'string:8 null',
                    'priority' => 'string:16', 'status' => 'string:20', 'created_by' => 'int null', 'created_at' => 'datetime',
                ],
                'fks' => [
                    'assignee_id' => 'employees.id SET NULL',
                    'customer_id' => 'customers.id SET NULL',
                    'created_by' => 'users.id SET NULL',
                ],
                'index' => [['assignee_id'], ['status'], ['due_date']],
            ],
            'notifications' => [
                'cols' => [
                    'id' => 'pk', 'user_id' => 'int', 'level' => 'string:16', 'title' => 'string:180', 'body' => 'text',
                    'entity_type' => 'string:40 null', 'entity_id' => 'int null', 'link' => 'string:180 null',
                    'read_at' => 'datetime null', 'created_at' => 'datetime',
                ],
                'fks' => ['user_id' => 'users.id CASCADE'],
                'index' => [['user_id'], ['read_at']],
            ],
            'notification_preferences' => [
                'cols' => ['id' => 'pk', 'user_id' => 'int', 'event_code' => 'string:40', 'channel' => 'string:20', 'enabled' => 'bool'],
                'fks' => ['user_id' => 'users.id CASCADE'],
            ],
            'push_subscriptions' => [
                'cols' => [
                    'id' => 'pk', 'user_id' => 'int', 'endpoint' => 'text', 'p256dh' => 'string:255',
                    'auth_key' => 'string:255', 'created_at' => 'datetime',
                ],
                'fks' => ['user_id' => 'users.id CASCADE'],
            ],
            'whatsapp_conversations' => [
                'cols' => [
                    'id' => 'pk', 'customer_id' => 'int null', 'phone' => 'string:32', 'phone_digits' => 'string:20',
                    'manager_id' => 'int null', 'last_message_at' => 'datetime null', 'unread_count' => 'int', 'created_at' => 'datetime',
                ],
                'fks' => ['customer_id' => 'customers.id SET NULL', 'manager_id' => 'employees.id SET NULL'],
                'index' => [['phone_digits']],
            ],
            'whatsapp_messages' => [
                'cols' => [
                    'id' => 'pk', 'conversation_id' => 'int', 'direction' => 'string:8', 'body' => 'text',
                    'status' => 'string:20', 'user_id' => 'int null', 'created_at' => 'datetime',
                ],
                'fks' => ['conversation_id' => 'whatsapp_conversations.id CASCADE', 'user_id' => 'users.id SET NULL'],
                'index' => [['conversation_id']],
            ],
            'message_templates' => [
                'cols' => [
                    'id' => 'pk', 'code' => 'string:40', 'name' => 'string:120', 'body' => 'text',
                    'channel' => 'string:20', 'updated_at' => 'datetime',
                ],
                'unique' => [['code']],
            ],
            'outbound_messages' => [
                'cols' => [
                    'id' => 'pk', 'customer_id' => 'int null', 'template_code' => 'string:40 null', 'channel' => 'string:20',
                    'body' => 'text', 'status' => 'string:20', 'created_by' => 'int null', 'created_at' => 'datetime',
                ],
                'fks' => ['customer_id' => 'customers.id SET NULL', 'created_by' => 'users.id SET NULL'],
            ],
            'automation_rules' => [
                'cols' => ['id' => 'pk', 'name' => 'string:160', 'trigger_code' => 'string:40', 'enabled' => 'bool', 'created_at' => 'datetime'],
            ],
            'automation_conditions' => [
                'cols' => ['id' => 'pk', 'rule_id' => 'int', 'field' => 'string:40', 'op' => 'string:16', 'value' => 'string:80'],
                'fks' => ['rule_id' => 'automation_rules.id CASCADE'],
            ],
            'automation_actions' => [
                'cols' => ['id' => 'pk', 'rule_id' => 'int', 'action_code' => 'string:40', 'param' => 'string:80 null'],
                'fks' => ['rule_id' => 'automation_rules.id CASCADE'],
            ],
            'automation_runs' => [
                'cols' => [
                    'id' => 'pk', 'rule_id' => 'int', 'entity_type' => 'string:40', 'entity_id' => 'int',
                    'cycle_key' => 'string:40', 'created_at' => 'datetime',
                ],
                'unique' => [['rule_id', 'entity_type', 'entity_id', 'cycle_key']],
                'fks' => ['rule_id' => 'automation_rules.id CASCADE'],
            ],
            'audit_logs' => [
                'cols' => [
                    'id' => 'pk', 'user_id' => 'int null', 'entity_type' => 'string:40', 'entity_id' => 'int null',
                    'action' => 'string:32', 'field' => 'string:64 null', 'old_value' => 'text null',
                    'new_value' => 'text null', 'created_at' => 'datetime',
                ],
                'fks' => ['user_id' => 'users.id SET NULL'],
                'index' => [['entity_type', 'entity_id']],
            ],
            'ai_conversations' => [
                'cols' => ['id' => 'pk', 'user_id' => 'int', 'title' => 'string:180', 'created_at' => 'datetime', 'updated_at' => 'datetime'],
                'fks' => ['user_id' => 'users.id CASCADE'],
            ],
            'ai_messages' => [
                'cols' => [
                    'id' => 'pk', 'conversation_id' => 'int', 'role' => 'string:16', 'content' => 'text',
                    'sources_text' => 'text null', 'created_at' => 'datetime',
                ],
                'fks' => ['conversation_id' => 'ai_conversations.id CASCADE'],
            ],
            'ai_message_links' => [
                'cols' => ['id' => 'pk', 'message_id' => 'int', 'entity_type' => 'string:32', 'entity_id' => 'int', 'label' => 'string:180'],
                'fks' => ['message_id' => 'ai_messages.id CASCADE'],
            ],
            'ai_tool_calls' => [
                'cols' => [
                    'id' => 'pk', 'conversation_id' => 'int', 'message_id' => 'int null', 'tool_name' => 'string:64',
                    'arguments_text' => 'text', 'result_text' => 'text', 'created_at' => 'datetime',
                ],
                'fks' => ['conversation_id' => 'ai_conversations.id CASCADE'],
            ],
            'settings' => [
                'cols' => ['name' => 'string:80', 'value' => 'text'],
                'pk' => ['name'],
            ],
        ];
    }

    private static function createTable(string $driver, string $name, array $def): string
    {
        $parts = [];
        foreach ($def['cols'] as $col => $type) {
            $parts[] = self::column($driver, $col, $type);
        }
        if (!empty($def['pk'])) {
            $quoted = array_map(fn (string $c) => self::qid($driver, $c), $def['pk']);
            $parts[] = 'PRIMARY KEY (' . implode(', ', $quoted) . ')';
        }
        foreach ($def['unique'] ?? [] as $cols) {
            $quoted = array_map(fn (string $c) => self::qid($driver, $c), $cols);
            $parts[] = 'UNIQUE (' . implode(', ', $quoted) . ')';
        }
        foreach ($def['fks'] ?? [] as $col => $ref) {
            [$target, $action] = array_pad(explode(' ', $ref, 2), 2, 'RESTRICT');
            [$table, $field] = explode('.', $target);
            $parts[] = 'FOREIGN KEY (' . self::qid($driver, $col) . ') REFERENCES ' . self::qid($driver, $table)
                . ' (' . self::qid($driver, $field) . ') ON DELETE ' . $action;
        }
        $sql = 'CREATE TABLE IF NOT EXISTS ' . self::qid($driver, $name) . ' (' . implode(', ', $parts) . ')';
        if ($driver === 'mysql') {
            $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
        }
        return $sql;
    }

    private static function column(string $driver, string $name, string $dsl): string
    {
        $null = str_contains($dsl, 'null');
        $base = trim(str_replace('null', '', $dsl));
        $nn = $null ? 'NULL' : 'NOT NULL';
        $q = self::qid($driver, $name);
        if ($base === 'pk') {
            return $driver === 'mysql'
                ? "$q BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY"
                : "$q INTEGER PRIMARY KEY AUTOINCREMENT";
        }
        $type = match (true) {
            $base === 'int' || $base === 'money' => $driver === 'mysql' ? 'BIGINT' : 'INTEGER',
            $base === 'bool' => $driver === 'mysql' ? 'TINYINT' : 'INTEGER',
            str_starts_with($base, 'string:') => $driver === 'mysql' ? 'VARCHAR(' . (int) substr($base, 7) . ')' : 'TEXT',
            $base === 'text' => 'TEXT',
            $base === 'date' => $driver === 'mysql' ? 'CHAR(10)' : 'TEXT',
            $base === 'datetime' => $driver === 'mysql' ? 'CHAR(19)' : 'TEXT',
            default => throw new RuntimeException('Unknown column type ' . $dsl),
        };
        return "$q $type $nn";
    }

    private static function qid(string $driver, string $name): string
    {
        return $driver === 'mysql' ? '`' . $name . '`' : '"' . $name . '"';
    }
}
