<?php
declare(strict_types=1);

final class Seed
{
    public const PASSWORD = 'FitDemo!2026';

    public static function run(Db $db, array $config): void
    {
        if ($db->one('SELECT id FROM users LIMIT 1')) {
            self::packageThreads($db);
            return;
        }
        $db->tx(function (Db $db) use ($config): void {
            self::access($db);
            $employees = self::employees($db);
            $programs = self::programs($db);
            self::catalog($db, $employees);
            self::customers($db, $employees, $programs);
            self::leads($db, $employees, $programs);
            self::messages($db);
            self::packageThreads($db);
            self::rules($db);
            set_setting($db, 'company_name', 'FOODFORFIT');
            set_setting($db, 'whatsapp_enabled', '0');
            set_setting($db, 'ai_mode', 'read_only');
            set_setting($db, 'ai_system_prompt', '');
        });
        AutomationService::runDaily($db, $config);
    }

    private static function access(Db $db): void
    {
        $perms = [
            'dashboard.view' => 'Главная',
            'leads.view' => 'Лиды',
            'leads.manage' => 'Изменение лидов',
            'leads.view_all' => 'Все лиды',
            'customers.view' => 'Клиенты',
            'customers.manage' => 'Изменение клиентов',
            'customers.view_all' => 'Все клиенты',
            'subscriptions.view' => 'Подписки',
            'subscriptions.manage' => 'Изменение подписок',
            'payments.view' => 'Оплаты',
            'payments.manage' => 'Проведение оплат',
            'finance.view' => 'Финансы',
            'deliveries.view' => 'Все доставки',
            'deliveries.manage' => 'Изменение доставок',
            'deliveries.own' => 'Свои доставки',
            'inventory.view' => 'Склад',
            'inventory.manage' => 'Операции склада',
            'programs.view' => 'Программы',
            'programs.manage' => 'Изменение программ',
            'employees.view' => 'Сотрудники',
            'employees.manage' => 'Изменение сотрудников',
            'tasks.view' => 'Задачи',
            'tasks.manage' => 'Изменение задач',
            'notifications.view' => 'Уведомления',
            'whatsapp.view' => 'WhatsApp',
            'whatsapp.send' => 'Сообщения WhatsApp',
            'analytics.view' => 'Аналитика',
            'analytics.view_all' => 'Аналитика компании',
            'settings.manage' => 'Настройки',
            'ai.use' => 'Grok Agent',
            'audit.view' => 'Аудит',
            'search.use' => 'Поиск',
            'system.manage' => 'Система',
        ];
        $permIds = [];
        foreach ($perms as $code => $name) {
            $permIds[$code] = $db->insert('INSERT INTO permissions (code, name) VALUES (:code, :name)', ['code' => $code, 'name' => $name]);
        }
        $all = array_keys($perms);
        $roles = [
            'super_admin' => ['Супер-админ', $all],
            'admin' => ['Админ', array_values(array_diff($all, ['system.manage']))],
            'sales_manager' => ['Менеджер продаж', ['dashboard.view', 'leads.view', 'leads.manage', 'customers.view', 'customers.manage', 'subscriptions.view', 'subscriptions.manage', 'payments.view', 'payments.manage', 'deliveries.view', 'programs.view', 'tasks.view', 'tasks.manage', 'notifications.view', 'whatsapp.view', 'whatsapp.send', 'analytics.view', 'ai.use', 'search.use']],
            'customer_manager' => ['Менеджер клиентов', ['dashboard.view', 'leads.view', 'customers.view', 'customers.view_all', 'customers.manage', 'subscriptions.view', 'subscriptions.manage', 'payments.view', 'deliveries.view', 'programs.view', 'tasks.view', 'tasks.manage', 'notifications.view', 'whatsapp.view', 'whatsapp.send', 'analytics.view', 'ai.use', 'search.use']],
            'warehouse_manager' => ['Заведующий складом', ['dashboard.view', 'inventory.view', 'inventory.manage', 'programs.view', 'tasks.view', 'notifications.view', 'ai.use', 'search.use']],
            'delivery_manager' => ['Менеджер доставки', ['dashboard.view', 'deliveries.view', 'deliveries.manage', 'customers.view', 'customers.view_all', 'programs.view', 'tasks.view', 'tasks.manage', 'notifications.view', 'ai.use', 'search.use']],
            'courier' => ['Курьер', ['dashboard.view', 'deliveries.own', 'tasks.view', 'notifications.view']],
            'accountant' => ['Бухгалтер', ['dashboard.view', 'finance.view', 'payments.view', 'payments.manage', 'analytics.view', 'analytics.view_all', 'customers.view', 'customers.view_all', 'subscriptions.view', 'audit.view', 'notifications.view', 'ai.use', 'search.use']],
            'viewer' => ['Наблюдатель', ['dashboard.view', 'customers.view', 'customers.view_all', 'leads.view', 'leads.view_all', 'subscriptions.view', 'deliveries.view', 'programs.view', 'inventory.view', 'tasks.view', 'analytics.view', 'notifications.view', 'search.use', 'ai.use']],
        ];
        foreach ($roles as $code => [$name, $codes]) {
            $roleId = $db->insert('INSERT INTO roles (code, name) VALUES (:code, :name)', ['code' => $code, 'name' => $name]);
            foreach ($codes as $perm) {
                $db->insert('INSERT INTO role_permissions (role_id, permission_id) VALUES (:role, :perm)', ['role' => $roleId, 'perm' => $permIds[$perm]]);
            }
        }
    }

    private static function employees(Db $db): array
    {
        $people = [
            ['Алия Бекова', 'super_admin', 'admin@foodforfit.kz', 'Директор', 'Управление'],
            ['Ерлан Садыков', 'admin', 'manager@foodforfit.kz', 'Операционный директор', 'Управление'],
            ['Мадина Омарова', 'sales_manager', 'sales@foodforfit.kz', 'Менеджер продаж', 'Продажи'],
            ['Алихан Тлеуберген', 'sales_manager', 'sales2@foodforfit.kz', 'Менеджер продаж', 'Продажи'],
            ['Диана Ким', 'customer_manager', 'care@foodforfit.kz', 'Менеджер заботы', 'Клиенты'],
            ['Нурлан Ахметов', 'warehouse_manager', 'warehouse@foodforfit.kz', 'Заведующий складом', 'Склад'],
            ['Сабина Жумагали', 'delivery_manager', 'delivery@foodforfit.kz', 'Руководитель доставки', 'Доставка'],
            ['Тимур Есенов', 'courier', 'courier@foodforfit.kz', 'Курьер', 'Доставка'],
            ['Асель Муратова', 'accountant', 'finance@foodforfit.kz', 'Бухгалтер', 'Финансы'],
            ['Дамир Алиев', 'viewer', 'viewer@foodforfit.kz', 'Аналитик', 'Офис'],
        ];
        $ids = [];
        $hash = password_hash(self::PASSWORD, PASSWORD_DEFAULT);
        foreach ($people as $i => $person) {
            [$name, $role, $email, $position, $department] = $person;
            $roleRow = $db->one('SELECT id FROM roles WHERE code = :code', ['code' => $role]);
            $phone = self::phone(100 + $i);
            $employeeId = $db->insert(
                'INSERT INTO employees (full_name, phone, phone_digits, email, position, role_id, department, hired_at, status, salary, manager_id, created_at)
                 VALUES (:name, :phone, :digits, :email, :position, :role, :department, :hired, :status, :salary, :manager, :created)',
                [
                    'name' => $name, 'phone' => $phone, 'digits' => phone_digits($phone), 'email' => $email,
                    'position' => $position, 'role' => $roleRow['id'], 'department' => $department,
                    'hired' => '2024-03-01', 'status' => 'active', 'salary' => 350000 + $i * 20000,
                    'manager' => $i === 0 ? null : 1, 'created' => app_now(),
                ],
            );
            $userId = $db->insert(
                'INSERT INTO users (employee_id, email, phone, password_hash, role_id, status, created_at)
                 VALUES (:employee, :email, :phone, :hash, :role, :status, :created)',
                ['employee' => $employeeId, 'email' => $email, 'phone' => $phone, 'hash' => $hash, 'role' => $roleRow['id'], 'status' => 'active', 'created' => app_now()],
            );
            $db->exec('UPDATE employees SET user_id = :user WHERE id = :id', ['user' => $userId, 'id' => $employeeId]);
            $ids[$role . ($role === 'sales_manager' && isset($ids['sales_manager']) ? '_2' : '')] = $employeeId;
        }
        return $ids;
    }

    private static function programs(Db $db): array
    {
        $rows = [
            ['Slim', 'slim', 'Мягкий дефицит калорий для снижения веса без срывов.', 1200, 89000, 21, 5],
            ['Balance', 'balance', 'Сбалансированный рацион на каждый день.', 1600, 99000, 28, 5],
            ['Fitness', 'fitness', 'Больше белка для тренировок и восстановления.', 2200, 115000, 28, 6],
            ['Premium', 'premium', 'Расширенное меню и десерты без сахара.', 1800, 145000, 28, 6],
            ['Individual', 'individual', 'Рацион под цель, аллергии и график клиента.', 2000, 168000, 14, 5],
        ];
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $db->insert(
                'INSERT INTO meal_programs (name, slug, description, calories, price, days, meals_per_day, available, created_at)
                 VALUES (:name, :slug, :description, :calories, :price, :days, :meals, 1, :created)',
                ['name' => $row[0], 'slug' => $row[1], 'description' => $row[2], 'calories' => $row[3], 'price' => $row[4], 'days' => $row[5], 'meals' => $row[6], 'created' => app_now()],
            );
        }
        return $ids;
    }

    private static function catalog(Db $db, array $employees): void
    {
        $categories = [
            'products' => 'Продукты', 'packaging' => 'Упаковка', 'drinks' => 'Напитки',
            'supplies' => 'Расходники', 'ready' => 'Готовая продукция', 'other' => 'Прочее',
        ];
        $catIds = [];
        foreach ($categories as $code => $name) {
            $catIds[] = $db->insert('INSERT INTO product_categories (code, name) VALUES (:code, :name)', ['code' => $code, 'name' => $name]);
        }
        $suppliers = [];
        foreach ([['Green Market', '+7 727 300 11 20'], ['Almaty Fresh', '+7 727 300 11 21'], ['Astana Pack', '+7 717 200 11 22']] as $s) {
            $suppliers[] = $db->insert('INSERT INTO suppliers (name, phone) VALUES (:name, :phone)', ['name' => $s[0], 'phone' => $s[1]]);
        }
        $names = ['Куриная грудка', 'Индейка', 'Лосось', 'Треска', 'Говядина', 'Гречка', 'Рис', 'Киноа', 'Брокколи', 'Шпинат', 'Огурец', 'Томаты', 'Авокадо', 'Яйцо', 'Творог', 'Йогурт', 'Миндаль', 'Оливковое масло', 'Нут', 'Чечевица', 'Контейнер 500 мл', 'Контейнер 750 мл', 'Крышка', 'Пакет крафт', 'Салфетка', 'Вилка', 'Вода 0.5', 'Морс', 'Смузи', 'Кефир', 'Перчатки', 'Плёнка', 'Этикетка', 'Термопакет', 'Ланч бокс', 'Соус йогурт', 'Соус песто', 'Хлебцы', 'Яблоко', 'Груша', 'Зелень', 'Лимон', 'Имбирь', 'Специи', 'Соль', 'Перец', 'Рис басмати', 'Булгур', 'Фасоль', 'Мёд'];
        for ($i = 0; $i < 50; $i++) {
            $min = $i >= 45 ? 20 : 8;
            $qty = $i >= 45 ? 3 : 20 + ($i % 15);
            $productId = $db->insert(
                'INSERT INTO products (name, sku, category_id, unit, min_stock, purchase_price, avg_price, supplier_id, expires_on, batch, created_at)
                 VALUES (:name, :sku, :category, :unit, :min, :purchase, :avg, :supplier, :expires, :batch, :created)',
                [
                    'name' => $names[$i],
                    'sku' => 'FF-' . str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                    'category' => $catIds[$i % count($catIds)],
                    'unit' => $i % 5 === 0 ? 'кг' : 'шт',
                    'min' => $min,
                    'purchase' => 800 + $i * 70,
                    'avg' => 900 + $i * 75,
                    'supplier' => $suppliers[$i % 3],
                    'expires' => app_date_shift(app_today(), 10 + $i),
                    'batch' => 'B-' . (2400 + $i),
                    'created' => app_now(),
                ],
            );
            $db->insert('INSERT INTO inventory (product_id, warehouse, quantity) VALUES (:product, :warehouse, :qty)', [
                'product' => $productId, 'warehouse' => 'Кухня', 'qty' => $qty,
            ]);
            $db->insert(
                'INSERT INTO inventory_transactions (product_id, warehouse, type, quantity, note, employee_id, created_at)
                 VALUES (:product, :warehouse, :type, :qty, :note, :employee, :created)',
                ['product' => $productId, 'warehouse' => 'Кухня', 'type' => 'in', 'qty' => $qty, 'note' => 'Стартовый приход', 'employee' => $employees['warehouse_manager'], 'created' => app_now()],
            );
        }
    }

    private static function customers(Db $db, array $employees, array $programs): void
    {
        $first = ['Айгерім', 'Алихан', 'Мадина', 'Данияр', 'Асель', 'Тимур', 'Сабина', 'Ерлан', 'Диана', 'Нурсултан', 'Камила', 'Бекзат', 'Алина', 'Руслан', 'Жанна', 'Арман', 'Меруерт', 'Санжар', 'Лаура', 'Олжас', 'Амина', 'Дамир', 'Индира', 'Бауржан', 'Томирис', 'Ерасыл', 'Аружан', 'Мирас', 'Назым', 'Айдос'];
        $last = ['Нұрланова', 'Сейтов', 'Касымова', 'Ахметов', 'Жумагали', 'Есенов', 'Ким', 'Садыков', 'Омарова', 'Тлеуберген', 'Бекова', 'Мусин', 'Алиева', 'Нуртазин', 'Смагулова', 'Каримов', 'Досжан', 'Абдрахманов', 'Ибраева', 'Кенжебаев', 'Сулейменова', 'Рахимов', 'Токтарова', 'Байжанов', 'Серікқызы', 'Жаксылыков', 'Калиева', 'Оспанов', 'Муратова', 'Нурпеисов'];
        $districts = ['Бостандыкский', 'Медеуский', 'Алмалинский', 'Ауэзовский', 'Есильский', 'Сарыарка'];
        $streets = ['Абая', 'Достык', 'Толе би', 'Сатпаева', 'Мангилик Ел', 'Кабанбай батыра', 'Туран', 'Розыбакиева'];
        $managers = [$employees['sales_manager'], $employees['sales_manager_2'], $employees['customer_manager']];
        $programRows = $db->all('SELECT * FROM meal_programs ORDER BY id');
        $payCount = 0;
        for ($i = 0; $i < 30; $i++) {
            [$offset, $status, $subStatus, $vip] = self::profile($i);
            if ($i === 6 || $i === 7) {
                $status = 'new';
            }
            $city = $i % 3 === 0 ? 'Астана' : 'Алматы';
            $phone = self::phone(200 + $i);
            $manager = $managers[$i % 3];
            $registered = $i >= 27 ? app_date_shift(app_today(), -($i - 26)) : app_date_shift(app_today(), -40 - $i);
            $customerId = $db->insert(
                'INSERT INTO customers (full_name, phone, phone_digits, whatsapp, status, vip, manager_id, source, city, district, comment, first_contact_at, first_payment_at, last_payment_at, last_contact_at, next_action, next_action_at, registered_at, created_at)
                 VALUES (:name, :phone, :digits, :whatsapp, :status, :vip, :manager, :source, :city, :district, :comment, :first, :first_pay, :last_pay, :contact, :action, :next, :registered, :created)',
                [
                    'name' => $first[$i] . ' ' . $last[$i],
                    'phone' => $phone,
                    'digits' => phone_digits($phone),
                    'whatsapp' => $phone,
                    'status' => $status,
                    'vip' => $vip,
                    'manager' => $manager,
                    'source' => ['instagram', 'site', 'referral', 'whatsapp'][$i % 4],
                    'city' => $city,
                    'district' => $districts[$i % count($districts)],
                    'comment' => $i % 5 === 0 ? 'Просит без орехов' : null,
                    'first' => $registered,
                    'first_pay' => $i < 22 || $i === 29 ? $registered : null,
                    'last_pay' => $i < 22 || $i === 29 ? app_date_shift(app_today(), -($i % 4)) : null,
                    'contact' => $i % 4 === 0 ? app_date_shift(app_today(), -20) : app_date_shift(app_today(), -2),
                    'action' => 'Напомнить о продлении',
                    'next' => app_today(),
                    'registered' => $registered,
                    'created' => $registered . ' 10:00:00',
                ],
            );
            $addressId = $db->insert(
                'INSERT INTO customer_addresses (customer_id, city, district, street, building, apartment, is_primary)
                 VALUES (:customer, :city, :district, :street, :building, :apt, 1)',
                ['customer' => $customerId, 'city' => $city, 'district' => $districts[$i % 6], 'street' => 'ул. ' . $streets[$i % 8], 'building' => (string) (10 + $i), 'apt' => (string) (($i % 40) + 1)],
            );
            foreach ([1, 3, 5] as $day) {
                $db->insert(
                    'INSERT INTO delivery_schedule_days (customer_id, weekday, slot, address_id) VALUES (:customer, :day, :slot, :address)',
                    ['customer' => $customerId, 'day' => $day, 'slot' => ['morning', 'day', 'evening'][$i % 3], 'address' => $addressId],
                );
            }
            $program = $programRows[$i % count($programRows)];
            $end = app_date_shift(app_today(), $offset);
            $start = app_date_shift($end, -((int) $program['days'] - 1));
            $price = (int) $program['price'];
            $discount = $i >= 11 && $i <= 14 ? (int) round($price * 0.1) : 0;
            $subId = $db->insert(
                'INSERT INTO subscriptions (customer_id, program_id, days, start_date, end_date, price, discount_amount, total_amount, payment_method, payment_status, status, manager_id, created_at)
                 VALUES (:customer, :program, :days, :start, :end, :price, :discount, :total, :method, :pay, :status, :manager, :created)',
                [
                    'customer' => $customerId, 'program' => $program['id'], 'days' => $program['days'], 'start' => $start, 'end' => $end,
                    'price' => $price, 'discount' => $discount, 'total' => $price - $discount, 'method' => 'kaspi',
                    'pay' => $i < 22 || $i === 29 ? 'paid' : 'pending', 'status' => $subStatus, 'manager' => $manager, 'created' => $start . ' 09:00:00',
                ],
            );
            $db->insert(
                'INSERT INTO subscription_items (subscription_id, title, calories, meals_per_day, price) VALUES (:sub, :title, :cal, :meals, :price)',
                ['sub' => $subId, 'title' => $program['name'], 'cal' => $program['calories'], 'meals' => $program['meals_per_day'], 'price' => $price],
            );
            if ($discount > 0) {
                $db->insert(
                    'INSERT INTO discounts (customer_id, subscription_id, percent, amount, base_amount, final_amount, reason, given_by, renewed_after, created_at)
                     VALUES (:customer, :sub, 10, :amount, :base, :final, :reason, :by, 0, :created)',
                    ['customer' => $customerId, 'sub' => $subId, 'amount' => $discount, 'base' => $price, 'final' => $price - $discount, 'reason' => 'Удержание', 'by' => $manager, 'created' => app_now()],
                );
            }
            $shouldPay = $i < 22 || $i === 29;
            if ($shouldPay) {
                $payDay = $payCount % 6 === 0 ? app_today() : app_date_shift(app_today(), -($payCount % 4));
                self::payment($db, $customerId, $subId, $manager, $price - $discount, $payCount, $payDay);
                $payCount++;
                if ($i < 7) {
                    self::payment($db, $customerId, $subId, $manager, $price, $payCount, app_date_shift(app_today(), -50));
                    $payCount++;
                }
            }
            if ($i < 18) {
                $statuses = ['preparing', 'ready', 'handed', 'on_the_way', 'delivered', 'problem'];
                $db->insert(
                    'INSERT INTO deliveries (customer_id, subscription_id, courier_id, delivery_date, address, phone, program_name, slot, comment, status, created_at)
                     VALUES (:customer, :sub, :courier, :date, :address, :phone, :program, :slot, :comment, :status, :created)',
                    [
                        'customer' => $customerId,
                        'sub' => $subId,
                        'courier' => $employees['courier'],
                        'date' => app_date_shift(app_today(), $i < 12 ? 0 : 1),
                        'address' => $city . ', ул. ' . $streets[$i % 8] . ', ' . (10 + $i),
                        'phone' => $phone,
                        'program' => $program['name'],
                        'slot' => ['morning', 'day', 'evening'][$i % 3],
                        'comment' => $i === 4 ? 'Домофон не работает' : null,
                        'status' => $i === 4 ? 'problem' : $statuses[$i % 5],
                        'created' => app_now(),
                    ],
                );
            }
        }
        for ($i = 22; $i <= 24; $i++) {
            $customerId = $i + 1;
            $sub = $db->one('SELECT id, total_amount, manager_id FROM subscriptions WHERE customer_id = :id ORDER BY id DESC LIMIT 1', ['id' => $customerId]);
            $n = str_pad((string) (100 + $i), 4, '0', STR_PAD_LEFT);
            $orderId = $db->insert(
                'INSERT INTO orders (number, customer_id, subscription_id, amount, status, created_at) VALUES (:number, :customer, :sub, :amount, :status, :created)',
                ['number' => 'FF-' . $n, 'customer' => $customerId, 'sub' => $sub['id'], 'amount' => $sub['total_amount'], 'status' => 'pending', 'created' => app_now()],
            );
            $db->insert(
                'INSERT INTO payments (number, customer_id, subscription_id, order_id, amount, method, status, manager_id, created_at)
                 VALUES (:number, :customer, :sub, :order_id, :amount, :method, :status, :manager, :created)',
                ['number' => 'PAY-' . $n, 'customer' => $customerId, 'sub' => $sub['id'], 'order_id' => $orderId, 'amount' => $sub['total_amount'], 'method' => 'kaspi', 'status' => 'pending', 'manager' => $sub['manager_id'], 'created' => app_now()],
            );
        }
        for ($i = 18; $i < 20; $i++) {
            $db->insert(
                'INSERT INTO deliveries (customer_id, courier_id, delivery_date, address, phone, program_name, slot, status, created_at)
                 VALUES (1, :courier, :date, :address, :phone, :program, :slot, :status, :created)',
                ['courier' => $employees['courier'], 'date' => app_today(), 'address' => 'Алматы, ул. Абая, 10', 'phone' => self::phone(200), 'program' => 'Balance', 'slot' => 'evening', 'status' => 'ready', 'created' => app_now()],
            );
        }
        foreach ([
            ['Позвонить клиенту для продления', $employees['sales_manager'], 1, -2, 'high', 'open'],
            ['Уточнить адрес', $employees['sales_manager_2'], 2, -1, 'normal', 'open'],
            ['Согласовать меню', $employees['customer_manager'], 3, -3, 'high', 'open'],
            ['Проверить оплату', $employees['sales_manager'], 4, -1, 'high', 'open'],
            ['Подтвердить доставку', $employees['delivery_manager'], 5, 0, 'normal', 'open'],
            ['Собрать отзыв', $employees['customer_manager'], 6, 0, 'low', 'open'],
            ['Отправить меню', $employees['sales_manager_2'], 7, 2, 'normal', 'open'],
            ['Закрыть вопрос по чеку', $employees['accountant'] ?? $employees['customer_manager'], 8, -5, 'normal', 'done'],
        ] as $task) {
            $db->insert(
                'INSERT INTO tasks (title, assignee_id, customer_id, due_date, priority, status, created_at)
                 VALUES (:title, :assignee, :customer, :due, :priority, :status, :created)',
                ['title' => $task[0], 'assignee' => $task[1], 'customer' => $task[2], 'due' => app_date_shift(app_today(), $task[3]), 'priority' => $task[4], 'status' => $task[5], 'created' => app_now()],
            );
        }
    }

    private static function payment(Db $db, int $customerId, int $subId, int $manager, int $amount, int $index, ?string $day = null): void
    {
        $day = $day ?? app_date_shift(app_today(), -20);
        $n = str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
        $orderId = $db->insert(
            'INSERT INTO orders (number, customer_id, subscription_id, amount, status, created_at) VALUES (:number, :customer, :sub, :amount, :status, :created)',
            ['number' => 'FF-' . $n, 'customer' => $customerId, 'sub' => $subId, 'amount' => $amount, 'status' => 'paid', 'created' => $day . ' 12:00:00'],
        );
        $db->insert(
            'INSERT INTO payments (number, customer_id, subscription_id, order_id, amount, method, status, manager_id, paid_at, created_at)
             VALUES (:number, :customer, :sub, :order_id, :amount, :method, :status, :manager, :paid, :created)',
            ['number' => 'PAY-' . $n, 'customer' => $customerId, 'sub' => $subId, 'order_id' => $orderId, 'amount' => $amount, 'method' => 'kaspi', 'status' => 'paid', 'manager' => $manager, 'paid' => $day . ' 12:10:00', 'created' => $day . ' 12:00:00'],
        );
    }

    private static function leads(Db $db, array $employees, array $programs): void
    {
        $names = ['Аружан Бек', 'Ильяс Нуров', 'Самал Ахмет', 'Бота Ермек', 'Даяна Сун', 'Марлен Каир', 'Айсулу Тан', 'Ренат Омар', 'Жания Кос', 'Темирлан Бай', 'Милана Ю', 'Еркежан Нур', 'Адиль Сеит', 'Карина Пак', 'Олжас Мука', 'Амина Жан', 'Серик Али', 'Лаура Кен', 'Никита Ор', 'Айдана Мух'];
        $stages = ['new', 'new', 'new', 'contacted', 'contacted', 'qualified', 'program_selected', 'proposal_sent', 'proposal_sent', 'awaiting_payment', 'awaiting_payment', 'rejected', 'no_answer', 'no_answer', 'contacted', 'qualified', 'new', 'proposal_sent', 'awaiting_payment', 'program_selected'];
        $managers = [$employees['sales_manager'], $employees['sales_manager_2']];
        foreach ($names as $i => $name) {
            $phone = self::phone(400 + $i);
            $db->insert(
                'INSERT INTO leads (name, phone, phone_digits, manager_id, source, program_id, expected_amount, stage, comment, last_contact_at, next_contact_at, created_at, updated_at)
                 VALUES (:name, :phone, :digits, :manager, :source, :program, :amount, :stage, :comment, :last, :next, :created, :updated)',
                [
                    'name' => $name, 'phone' => $phone, 'digits' => phone_digits($phone), 'manager' => $managers[$i % 2],
                    'source' => ['instagram', 'site', 'call', 'referral'][$i % 4], 'program' => $programs[$i % count($programs)],
                    'amount' => 89000 + ($i % 5) * 10000, 'stage' => $stages[$i], 'comment' => $i % 6 === 0 ? 'Просит перезвонить после 19:00' : null,
                    'last' => app_date_shift(app_today(), -($i % 5)), 'next' => app_date_shift(app_today(), $i % 3),
                    'created' => app_date_shift(app_today(), -($i % 8)) . ' 11:00:00', 'updated' => app_now(),
                ],
            );
        }
    }

    private static function messages(Db $db): void
    {
        $templates = [
            ['renew_7', 'За 7 дней', 'Здравствуйте, {name}. Напоминаем, что программа {program} заканчивается {subscription_end}.'],
            ['renew_3', 'За 3 дня', 'Здравствуйте, {name}. Ваша программа питания заканчивается {subscription_end}. Хотите продлить питание на следующий период?'],
            ['renew_1', 'За 1 день', 'Здравствуйте, {name}. Завтра заканчивается ваша программа питания. Мы можем продлить подписку без перерыва.'],
            ['renew_0', 'В день окончания', 'Здравствуйте, {name}. Сегодня заканчивается ваша программа питания. Продлить программу можно прямо сейчас.'],
            ['renew_after', 'После окончания', 'Здравствуйте, {name}. Ваша программа питания завершилась. Будем рады продолжить питание для вас.'],
            ['renew_offer', 'Предложение продления', 'Здравствуйте, {name}. Можем продлить {program} до {subscription_end}. Стоимость {price}. Оплата: {payment_link}'],
        ];
        foreach ($templates as $tpl) {
            $db->insert(
                'INSERT INTO message_templates (code, name, body, channel, updated_at) VALUES (:code, :name, :body, :channel, :updated)',
                ['code' => $tpl[0], 'name' => $tpl[1], 'body' => $tpl[2], 'channel' => 'whatsapp', 'updated' => app_now()],
            );
        }
        for ($i = 1; $i <= 6; $i++) {
            $customer = $db->one('SELECT * FROM customers WHERE id = :id', ['id' => $i]);
            $cid = $db->insert(
                'INSERT INTO whatsapp_conversations (customer_id, phone, phone_digits, manager_id, last_message_at, unread_count, created_at)
                 VALUES (:customer, :phone, :digits, :manager, :last, :unread, :created)',
                ['customer' => $i, 'phone' => $customer['phone'], 'digits' => $customer['phone_digits'], 'manager' => $customer['manager_id'], 'last' => app_now(), 'unread' => $i < 3 ? 1 : 0, 'created' => app_now()],
            );
            $db->insert(
                'INSERT INTO whatsapp_messages (conversation_id, direction, body, status, created_at) VALUES (:conversation, :direction, :body, :status, :created)',
                ['conversation' => $cid, 'direction' => 'in', 'body' => 'Здравствуйте, хочу продлить питание.', 'status' => 'received', 'created' => app_now()],
            );
            $db->insert(
                'INSERT INTO whatsapp_messages (conversation_id, direction, body, status, created_at) VALUES (:conversation, :direction, :body, :status, :created)',
                ['conversation' => $cid, 'direction' => 'out', 'body' => 'Добрый день! Сейчас посмотрю вашу подписку.', 'status' => 'draft', 'created' => app_now()],
            );
        }
    }

    private static function packageThreads(Db $db): void
    {
        if (setting($db, 'demo_package_chats', '0') === '1') {
            return;
        }
        $programs = $db->all('SELECT * FROM meal_programs ORDER BY id');
        $customers = $db->all(
            'SELECT c.* FROM customers c WHERE NOT EXISTS (SELECT 1 FROM whatsapp_conversations w WHERE w.customer_id = c.id) ORDER BY c.id',
        );
        if (!$programs || count($customers) < count($programs)) {
            return;
        }
        $scripts = [
            'slim' => [
                ['in', 'Здравствуйте! Хочу похудеть и смотрю пакет Slim. Это правда {calories} ккал?'],
                ['out', 'Да. Slim — мягкий дефицит: {calories} ккал, {meals} приёмов в день, {days} дней. Стоимость {price}.'],
                ['in', 'Если будет сильно голодно, смогу потом перейти на Balance?'],
                ['out', 'Можно. Balance уже сытнее и идёт на полный месяц. Для старта без тяжёлых тренировок Slim обычно хватает.'],
                ['in', 'Тогда беру Slim на {days} дней. Как оплатить?'],
                ['out', 'Сейчас отправлю счёт на Slim, {price}. После оплаты соберём меню и график доставки.'],
            ],
            'balance' => [
                ['in', 'Добрый день. Хочу питание на каждый день, без жёсткой сушки. Balance подойдёт?'],
                ['out', 'Да. Balance — сбалансированный рацион: {calories} ккал, {meals} приёмов, {days} дней. Стоимость {price}.'],
                ['in', 'Сравнивала со Slim. Он дешевле, но калорий меньше, а я весь день в офисе.'],
                ['out', 'Тогда Balance спокойнее: это полный месяц без сильного голода. Slim короче и с большим дефицитом.'],
                ['in', 'Беру Balance на {days} дней.'],
                ['out', 'Отлично. Счёт на Balance, {price}. Напишите удобный слот: утро, день или вечер.'],
            ],
            'fitness' => [
                ['in', 'Тренируюсь 4 раза в неделю. Нужен пакет с белком, смотрю Fitness.'],
                ['out', 'Fitness для этого: {calories} ккал, {meals} приёмов, {days} дней, {price}. Белка больше, чем в Balance.'],
                ['in', 'В Balance калорий мало после зала. В Fitness правда {meals} приёмов?'],
                ['out', 'Да, отдельный приём после тренировки. Premium тоже на {meals} приёмов, но он про меню и десерты, не про белок.'],
                ['in', 'Тогда Fitness. Можно начать с понедельника?'],
                ['out', 'Да. Счёт на Fitness, {price}. После оплаты зафиксируем старт в понедельник.'],
            ],
            'premium' => [
                ['in', 'Хочу вкусное меню, не диетическую грусть. Что входит в Premium?'],
                ['out', 'Premium: {calories} ккал, {meals} приёмов, {days} дней, расширенное меню и десерты без сахара. {price}.'],
                ['in', 'Fitness дешевле и калорийнее. За что доплата в Premium?'],
                ['out', 'За стол и десерт. Если цель — тренировки и белок, лучше Fitness. Если важен вкус, берите Premium.'],
                ['in', 'Мне десерт важнее. Беру Premium.'],
                ['out', 'Счёт на Premium, {price}, {days} дней. Напишите, если какие-то продукты не едите.'],
            ],
            'individual' => [
                ['in', 'У меня непереносимость лактозы и плавающий график. Индивидуальный пакет ещё есть?'],
                ['out', 'Да, Individual: рацион под цель, аллергии и график. {calories} ккал, {meals} приёмов, {days} дней, {price}.'],
                ['in', 'Срок короче, чем у Balance. Это пробный?'],
                ['out', 'Да, {days} дней, чтобы собрать рацион под вас. Лактозу уберём. Если зайдёт, продлим на более длинный срок.'],
                ['in', 'Давайте Individual, без молока и творога.'],
                ['out', 'Принято. Счёт на Individual, {price}. В рационе укажу: без лактозы.'],
            ],
        ];
        foreach ($programs as $index => $program) {
            $slug = (string) $program['slug'];
            if (!isset($scripts[$slug])) {
                continue;
            }
            $customer = $customers[$index];
            $price = number_format((int) $program['price'], 0, '', ' ') . ' ₸';
            $replace = [
                '{calories}' => (string) $program['calories'],
                '{meals}' => (string) $program['meals_per_day'],
                '{days}' => (string) $program['days'],
                '{price}' => $price,
            ];
            $start = time() - (count($programs) - $index) * 7200;
            $last = '';
            $cid = $db->insert(
                'INSERT INTO whatsapp_conversations (customer_id, phone, phone_digits, manager_id, last_message_at, unread_count, created_at)
                 VALUES (:customer, :phone, :digits, :manager, :last, :unread, :created)',
                [
                    'customer' => $customer['id'],
                    'phone' => $customer['phone'],
                    'digits' => $customer['phone_digits'],
                    'manager' => $customer['manager_id'],
                    'last' => date('Y-m-d H:i:s', $start),
                    'unread' => $index % 2 === 0 ? 1 : 0,
                    'created' => date('Y-m-d H:i:s', $start),
                ],
            );
            foreach ($scripts[$slug] as $step => $line) {
                $at = date('Y-m-d H:i:s', $start + ($step + 1) * 180);
                $last = $at;
                $db->insert(
                    'INSERT INTO whatsapp_messages (conversation_id, direction, body, status, created_at) VALUES (:conversation, :direction, :body, :status, :created)',
                    [
                        'conversation' => $cid,
                        'direction' => $line[0],
                        'body' => strtr($line[1], $replace),
                        'status' => $line[0] === 'in' ? 'received' : 'draft',
                        'created' => $at,
                    ],
                );
            }
            $db->exec('UPDATE whatsapp_conversations SET last_message_at = :last WHERE id = :id', ['last' => $last, 'id' => $cid]);
        }
        set_setting($db, 'demo_package_chats', '1');
    }

    private static function rules(Db $db): void
    {
        $defs = [
            ['За 7 дней до окончания', 'subscription_days', '7', [['notify', 'info']]],
            ['За 3 дня до окончания', 'subscription_days', '3', [['notify', 'critical'], ['task', null], ['draft_message', 'renew_3']]],
            ['За 1 день до окончания', 'subscription_days', '1', [['notify', 'critical'], ['draft_message', 'renew_1']]],
            ['В день окончания', 'subscription_days', '0', [['notify', 'critical'], ['draft_message', 'renew_0']]],
            ['После окончания', 'subscription_days', '-1', [['notify', 'critical'], ['draft_message', 'renew_after']]],
            ['Оплата получена', 'payment_received', null, [['recorded', null]]],
            ['Остаток ниже минимума', 'stock_below_min', null, [['notify', 'important']]],
        ];
        foreach ($defs as $def) {
            $id = $db->insert(
                'INSERT INTO automation_rules (name, trigger_code, enabled, created_at) VALUES (:name, :trigger, 1, :created)',
                ['name' => $def[0], 'trigger' => $def[1], 'created' => app_now()],
            );
            if ($def[2] !== null) {
                $db->insert(
                    'INSERT INTO automation_conditions (rule_id, field, op, value) VALUES (:rule, :field, :op, :value)',
                    ['rule' => $id, 'field' => 'days', 'op' => 'eq', 'value' => $def[2]],
                );
            }
            foreach ($def[3] as $action) {
                $db->insert(
                    'INSERT INTO automation_actions (rule_id, action_code, param) VALUES (:rule, :code, :param)',
                    ['rule' => $id, 'code' => $action[0], 'param' => $action[1]],
                );
            }
        }
    }

    private static function profile(int $i): array
    {
        if ($i < 8) return [20 + $i, 'active', 'active', $i === 0 ? 1 : 0];
        if ($i < 11) return [7, 'active', 'active', 0];
        if ($i < 15) return [3, 'active', 'ending', 0];
        if ($i < 18) return [1, 'active', 'ending', 0];
        if ($i < 21) return [0, 'active', 'ending', 0];
        if ($i < 25) return [-2 - ($i - 21), 'renewal_required', 'awaiting_renewal', 0];
        if ($i < 27) return [10, 'paused', 'paused', 0];
        if ($i < 29) return [-15, 'lost', 'cancelled', 0];
        return [12, 'active', 'active', 1];
    }

    private static function phone(int $n): string
    {
        $digits = '7700' . str_pad((string) $n, 7, '0', STR_PAD_LEFT);
        return phone_format($digits);
    }
}
