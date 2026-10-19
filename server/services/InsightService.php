<?php
declare(strict_types=1);

final class InsightService
{
    public static function dashboard(Db $db, array $actor): array
    {
        actor_require($actor, 'dashboard.view');
        $finance = actor_can($actor, 'finance.view') || actor_can($actor, 'analytics.view_all');
        $ownOnly = !$finance && !actor_can($actor, 'customers.view_all');
        $today = app_today();
        $month = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');
        $paidToday = self::sumPaid($db, '', $ownOnly ? (int) $actor['employee_id'] : null, $today, $today);
        $paidMonth = self::sumPaid($db, '', $ownOnly ? (int) $actor['employee_id'] : null, $month, null);
        $customers = self::customerCounts($db, $ownOnly ? ' AND c.manager_id = :manager' : '', $ownOnly ? (int) $actor['employee_id'] : null);
        $leadsTotal = self::count($db, 'SELECT COUNT(*) AS c FROM leads l WHERE 1=1' . ($ownOnly ? ' AND l.manager_id = :manager' : ''), $ownOnly ? ['manager' => $actor['employee_id']] : []);
        $leadsWon = self::count($db, "SELECT COUNT(*) AS c FROM leads l WHERE l.stage IN ('paid','active_client')" . ($ownOnly ? ' AND l.manager_id = :manager' : ''), $ownOnly ? ['manager' => $actor['employee_id']] : []);
        $newLeads = self::count($db, "SELECT COUNT(*) AS c FROM leads l WHERE l.stage = 'new'" . ($ownOnly ? ' AND l.manager_id = :manager' : ''), $ownOnly ? ['manager' => $actor['employee_id']] : []);
        $avgSql = "SELECT COALESCE(AVG(p.amount), 0) AS c FROM payments p JOIN customers c ON c.id = p.customer_id WHERE p.status = 'paid'";
        $avgParams = [];
        if ($ownOnly) {
            $avgSql .= ' AND c.manager_id = :manager';
            $avgParams['manager'] = $actor['employee_id'];
        }
        $avg = self::count($db, $avgSql, $avgParams);
        $mrr = self::mrr($db, $ownOnly ? (int) $actor['employee_id'] : null);
        $showMoney = $finance || ($ownOnly && actor_can($actor, 'payments.view'));
        $deliveries = self::count(
            $db,
            'SELECT COUNT(*) AS c FROM deliveries d WHERE d.delivery_date = :today' . (actor_can($actor, 'deliveries.own') && !actor_can($actor, 'deliveries.view') ? ' AND d.courier_id = :courier' : ''),
            actor_can($actor, 'deliveries.own') && !actor_can($actor, 'deliveries.view')
                ? ['today' => $today, 'courier' => $actor['employee_id']]
                : ['today' => $today],
        );
        return [
            'kpis' => [
                'revenue_today' => $showMoney ? $paidToday : null,
                'revenue_month' => $showMoney ? $paidMonth : null,
                'new_customers' => $customers['new_month'],
                'active_customers' => $customers['active'],
                'renewal_customers' => $customers['renewal'],
                'ending_today' => $customers['ending_today'],
                'ending_1_3' => $customers['ending_1_3'],
                'overdue' => $customers['overdue'],
                'new_leads' => $newLeads,
                'conversion' => $leadsTotal ? round($leadsWon / $leadsTotal * 100, 1) : 0,
                'avg_check' => $showMoney ? (int) round($avg) : null,
                'mrr' => $showMoney ? $mrr : null,
                'deliveries_today' => actor_can($actor, 'deliveries.view') || actor_can($actor, 'deliveries.own') || actor_can($actor, 'deliveries.manage') ? $deliveries : null,
            ],
            'attention' => self::attention($db, $actor),
            'focus' => self::focus($db, $actor),
            'nutrition' => NutritionService::dashboardStats($db, $actor),
        ];
    }

    public static function badges(Db $db, array $actor): array
    {
        $unread = (int) $db->one('SELECT COUNT(*) AS c FROM notifications WHERE user_id = :id AND read_at IS NULL', ['id' => $actor['id']])['c'];
        $summary = actor_can($actor, 'customers.view') ? CrmService::summary($db, $actor) : ['overdue' => 0, 'today' => 0, 'tomorrow' => 0, 'in_3_days' => 0, 'renewal' => 0];
        $tasks = 0;
        if (actor_can($actor, 'tasks.view')) {
            $params = ['today' => app_today()];
            $sql = "SELECT COUNT(*) AS c FROM tasks WHERE status != 'done' AND due_date < :today";
            if (!actor_can($actor, 'employees.manage') && !actor_can($actor, 'customers.view_all')) {
                $sql .= ' AND assignee_id = :assignee';
                $params['assignee'] = $actor['employee_id'];
            }
            $tasks = (int) $db->one($sql, $params)['c'];
        }
        return [
            'notifications' => $unread,
            'customers' => $summary['today'] + $summary['overdue'],
            'renewals' => $summary['renewal'] + $summary['overdue'],
            'tasks' => $tasks,
        ];
    }

    public static function finance(Db $db, array $actor, string $period): array
    {
        actor_require($actor, 'finance.view');
        [$from, $to] = self::range($period);
        $paid = self::sumPaid($db, '', null, $from, $to);
        $refunds = (int) $db->one(
            "SELECT COALESCE(SUM(amount),0) AS c FROM payments WHERE status = 'refunded' AND substr(created_at,1,10) >= :from AND substr(created_at,1,10) <= :to",
            ['from' => $from, 'to' => $to],
        )['c'];
        $discounts = (int) $db->one(
            'SELECT COALESCE(SUM(amount),0) AS c FROM discounts WHERE substr(created_at,1,10) >= :from AND substr(created_at,1,10) <= :to',
            ['from' => $from, 'to' => $to],
        )['c'];
        $checks = (int) $db->one(
            "SELECT COUNT(*) AS c FROM payments WHERE status = 'paid' AND substr(paid_at,1,10) >= :from AND substr(paid_at,1,10) <= :to",
            ['from' => $from, 'to' => $to],
        )['c'];
        return [
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'revenue' => $paid,
            'refunds' => $refunds,
            'discounts' => $discounts,
            'avg_check' => $checks ? (int) round($paid / $checks) : 0,
            'mrr' => self::mrr($db, null),
            'ltv' => self::ltv($db),
            'retention' => self::retention($db),
            'churn' => self::churn($db),
            'by_program' => $db->all(
                "SELECT p.name, COALESCE(SUM(pay.amount),0) AS revenue, COUNT(pay.id) AS sales
                 FROM meal_programs p
                 LEFT JOIN subscriptions s ON s.program_id = p.id
                 LEFT JOIN payments pay ON pay.subscription_id = s.id AND pay.status = 'paid' AND substr(pay.paid_at,1,10) >= :from AND substr(pay.paid_at,1,10) <= :to
                 GROUP BY p.id ORDER BY revenue DESC",
                ['from' => $from, 'to' => $to],
            ),
            'by_manager' => $db->all(
                "SELECT e.full_name, COALESCE(SUM(p.amount),0) AS revenue, COUNT(p.id) AS sales
                 FROM employees e
                 LEFT JOIN payments p ON p.manager_id = e.id AND p.status = 'paid' AND substr(p.paid_at,1,10) >= :from AND substr(p.paid_at,1,10) <= :to
                 GROUP BY e.id ORDER BY revenue DESC",
                ['from' => $from, 'to' => $to],
            ),
            'discount_stats' => [
                'count' => (int) $db->one('SELECT COUNT(*) AS c FROM discounts')['c'],
                'sum' => (int) $db->one('SELECT COALESCE(SUM(amount),0) AS c FROM discounts')['c'],
                'renewed' => (int) $db->one('SELECT COUNT(*) AS c FROM discounts WHERE renewed_after = 1')['c'],
                'by_manager' => $db->all(
                    'SELECT e.full_name, COUNT(d.id) AS given, COALESCE(SUM(d.amount),0) AS amount,
                            SUM(CASE WHEN d.renewed_after = 1 THEN 1 ELSE 0 END) AS renewed
                     FROM discounts d LEFT JOIN employees e ON e.id = d.given_by
                     GROUP BY d.given_by ORDER BY amount DESC',
                ),
            ],
        ];
    }

    public static function analytics(Db $db, array $actor, string $period): array
    {
        actor_require($actor, 'analytics.view');
        $all = actor_can($actor, 'analytics.view_all') || actor_can($actor, 'finance.view');
        [$from, $to] = self::range($period);
        $manager = $all ? null : $actor['employee_id'];
        $leadWhere = $manager ? ' AND manager_id = :manager' : '';
        $params = ['from' => $from, 'to' => $to];
        if ($manager) {
            $params['manager'] = $manager;
        }
        $leads = self::count($db, 'SELECT COUNT(*) AS c FROM leads WHERE substr(created_at,1,10) >= :from AND substr(created_at,1,10) <= :to' . $leadWhere, $params);
        $won = self::count($db, "SELECT COUNT(*) AS c FROM leads WHERE stage IN ('paid','active_client') AND substr(updated_at,1,10) >= :from AND substr(updated_at,1,10) <= :to" . $leadWhere, $params);
        $customers = self::customerCounts($db, $manager ? ' AND manager_id = :manager' : '', $manager);
        return [
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'sales' => [
                'leads' => $leads,
                'won' => $won,
                'conversion' => $leads ? round($won / max(1, $leads) * 100, 1) : 0,
                'revenue' => $all || $manager ? self::sumPaid($db, $manager ? ' AND c.manager_id = :manager' : '', $manager, $from, $to) : null,
                'avg_check' => $all || $manager ? self::avgPaid($db, $manager, $from, $to) : null,
                'renewals' => (int) $db->one("SELECT COUNT(*) AS c FROM payments WHERE status = 'paid' AND substr(paid_at,1,10) >= :from AND substr(paid_at,1,10) <= :to", ['from' => $from, 'to' => $to])['c'],
            ],
            'customers' => [
                'new' => $customers['new_month'],
                'active' => $customers['active'],
                'lost' => $customers['lost'],
                'renewal' => $customers['renewal'],
                'retention' => self::retention($db),
                'churn' => self::churn($db),
            ],
            'managers' => $all ? $db->all(
                "SELECT e.full_name,
                        (SELECT COUNT(*) FROM leads l WHERE l.manager_id = e.id) AS leads,
                        (SELECT COUNT(*) FROM leads l WHERE l.manager_id = e.id AND l.stage IN ('paid','active_client')) AS sales,
                        (SELECT COALESCE(SUM(amount),0) FROM payments p WHERE p.manager_id = e.id AND p.status = 'paid') AS revenue
                 FROM employees e ORDER BY revenue DESC",
            ) : [],
            'programs' => $db->all(
                "SELECT p.name, COUNT(s.id) AS sales, COALESCE(SUM(CASE WHEN pay.status = 'paid' THEN pay.amount ELSE 0 END),0) AS revenue
                 FROM meal_programs p
                 LEFT JOIN subscriptions s ON s.program_id = p.id
                 LEFT JOIN payments pay ON pay.subscription_id = s.id
                 GROUP BY p.id ORDER BY revenue DESC",
            ),
        ];
    }

    public static function search(Db $db, array $actor, string $q): array
    {
        actor_require($actor, 'search.use');
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return ['customers' => [], 'leads' => [], 'orders' => [], 'subscriptions' => [], 'payments' => [], 'products' => [], 'employees' => []];
        }
        $like = '%' . $q . '%';
        $digit = phone_digits($q);
        $hasDigits = strlen($digit) >= 3;
        $digits = '%' . $digit . '%';
        $customers = [];
        if (actor_can($actor, 'customers.view')) {
            $scope = actor_can($actor, 'customers.view_all') || actor_can($actor, 'finance.view') ? '' : ' AND manager_id = :manager';
            $params = ['like' => $like];
            if ($hasDigits) {
                $params['digits'] = $digits;
            }
            if ($scope !== '') {
                $params['manager'] = $actor['employee_id'];
            }
            $phoneSql = $hasDigits ? ' OR phone LIKE :like OR phone_digits LIKE :digits' : '';
            $customers = $db->all('SELECT id, full_name, phone, status FROM customers WHERE (full_name LIKE :like' . $phoneSql . ')' . $scope . ' LIMIT 8', $params);
        }
        $leadPhone = $hasDigits ? ' OR phone_digits LIKE :digits' : '';
        $leadParams = ['like' => $like];
        if ($hasDigits) {
            $leadParams['digits'] = $digits;
        }
        $leads = actor_can($actor, 'leads.view')
            ? $db->all('SELECT id, name, phone, stage FROM leads WHERE name LIKE :like' . $leadPhone . ' LIMIT 8', $leadParams)
            : [];
        $orders = actor_can($actor, 'payments.view')
            ? $db->all('SELECT id, number, amount, status FROM orders WHERE number LIKE :like LIMIT 8', ['like' => $like])
            : [];
        $subPhone = $hasDigits ? ' OR c.phone_digits LIKE :digits' : '';
        $subParams = ['like' => $like];
        if ($hasDigits) {
            $subParams['digits'] = $digits;
        }
        $subscriptions = actor_can($actor, 'subscriptions.view')
            ? $db->all('SELECT s.id, c.full_name, s.status, s.end_date FROM subscriptions s JOIN customers c ON c.id = s.customer_id WHERE c.full_name LIKE :like' . $subPhone . ' LIMIT 8', $subParams)
            : [];
        $payments = actor_can($actor, 'payments.view')
            ? $db->all('SELECT id, number, amount, status FROM payments WHERE number LIKE :like LIMIT 8', ['like' => $like])
            : [];
        $products = actor_can($actor, 'inventory.view')
            ? $db->all('SELECT id, name, sku FROM products WHERE name LIKE :like OR sku LIKE :like LIMIT 8', ['like' => $like])
            : [];
        $empPhone = $hasDigits ? ' OR phone_digits LIKE :digits' : '';
        $empParams = ['like' => $like];
        if ($hasDigits) {
            $empParams['digits'] = $digits;
        }
        $employees = actor_can($actor, 'employees.view')
            ? $db->all('SELECT id, full_name, phone, position FROM employees WHERE full_name LIKE :like' . $empPhone . ' LIMIT 8', $empParams)
            : [];
        return compact('customers', 'leads', 'orders', 'subscriptions', 'payments', 'products', 'employees');
    }

    public static function audit(Db $db, array $actor, int $page): array
    {
        actor_require($actor, 'audit.view');
        $per = 30;
        $total = (int) $db->one('SELECT COUNT(*) AS c FROM audit_logs')['c'];
        $rows = $db->all(
            'SELECT a.*, u.email FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per),
        );
        return ['data' => $rows, 'page' => $page, 'per_page' => $per, 'total' => $total];
    }

    private static function attention(Db $db, array $actor): array
    {
        $items = [];
        if (actor_can($actor, 'customers.view')) {
            $summary = CrmService::summary($db, $actor);
            $map = [
                ['ending_today', 'Подписка заканчивается сегодня', $summary['today'], 'critical'],
                ['ending_tomorrow', 'Подписка заканчивается завтра', $summary['tomorrow'], 'critical'],
                ['ending_3', 'Подписка заканчивается через 3 дня', $summary['in_3_days'], 'important'],
                ['overdue', 'Клиент давно не продлевал', $summary['overdue'], 'critical'],
            ];
            foreach ($map as [$filter, $title, $count, $tone]) {
                if ($count > 0) {
                    $items[] = ['title' => $title, 'subtitle' => $count . ' клиентов', 'href' => '/customers?filter=' . $filter, 'tone' => $tone];
                }
            }
            $silentParams = ['border' => app_date_shift(app_today(), -14)];
            $silentSql = "SELECT COUNT(*) AS c FROM customers WHERE status = 'active' AND (last_contact_at IS NULL OR last_contact_at < :border)";
            if (!actor_can($actor, 'customers.view_all') && !actor_can($actor, 'finance.view')) {
                $silentSql .= ' AND manager_id = :manager';
                $silentParams['manager'] = $actor['employee_id'];
            }
            $silent = (int) $db->one($silentSql, $silentParams)['c'];
            if ($silent) {
                $items[] = ['title' => 'Клиенту давно не писали', 'subtitle' => $silent . ' клиентов', 'href' => '/customers?filter=active', 'tone' => 'important'];
            }
        }
        if (actor_can($actor, 'payments.view')) {
            $unpaid = (int) $db->one("SELECT COUNT(*) AS c FROM orders WHERE status = 'pending'")['c'];
            if ($unpaid) {
                $items[] = ['title' => 'Неоплаченный заказ', 'subtitle' => $unpaid . ' заказов', 'href' => '/orders?status=pending', 'tone' => 'important'];
            }
        }
        if (actor_can($actor, 'leads.view')) {
            $fresh = (int) $db->one("SELECT COUNT(*) AS c FROM leads WHERE stage = 'new'")['c'];
            if ($fresh) {
                $items[] = ['title' => 'Новая заявка без обработки', 'subtitle' => $fresh . ' лидов', 'href' => '/pipeline', 'tone' => 'info'];
            }
        }
        if (actor_can($actor, 'tasks.view')) {
            $over = (int) $db->one("SELECT COUNT(*) AS c FROM tasks WHERE status != 'done' AND due_date < :today", ['today' => app_today()])['c'];
            if ($over) {
                $items[] = ['title' => 'Просроченная задача', 'subtitle' => $over . ' задач', 'href' => '/employees?tab=tasks&filter=overdue', 'tone' => 'critical'];
            }
        }
        if (actor_can($actor, 'deliveries.view') || actor_can($actor, 'deliveries.manage')) {
            $problems = (int) $db->one("SELECT COUNT(*) AS c FROM deliveries WHERE status = 'problem'")['c'];
            if ($problems) {
                $items[] = ['title' => 'Проблема с доставкой', 'subtitle' => $problems . ' доставок', 'href' => '/deliveries?status=problem', 'tone' => 'critical'];
            }
        }
        if (actor_can($actor, 'inventory.view')) {
            $low = (int) $db->one('SELECT COUNT(*) AS c FROM inventory i JOIN products p ON p.id = i.product_id WHERE i.quantity < p.min_stock')['c'];
            if ($low) {
                $items[] = ['title' => 'Низкий остаток товара', 'subtitle' => $low . ' позиций', 'href' => '/inventory?low=1', 'tone' => 'important'];
            }
        }
        return $items;
    }

    private static function focus(Db $db, array $actor): array
    {
        $summary = actor_can($actor, 'customers.view') ? CrmService::summary($db, $actor) : ['today' => 0, 'tomorrow' => 0, 'in_3_days' => 0];
        $ending = $summary['today'] + $summary['tomorrow'] + $summary['in_3_days'];
        return [
            ['label' => 'Клиенты заканчивают подписку', 'value' => $ending, 'href' => '/customers?filter=renewal'],
            ['label' => 'Платежи ожидают оплаты', 'value' => actor_can($actor, 'payments.view') ? (int) $db->one("SELECT COUNT(*) AS c FROM payments WHERE status = 'pending'")['c'] : 0, 'href' => '/orders?status=pending'],
            ['label' => 'Новые лиды', 'value' => actor_can($actor, 'leads.view') ? (int) $db->one("SELECT COUNT(*) AS c FROM leads WHERE stage = 'new'")['c'] : 0, 'href' => '/pipeline'],
            ['label' => 'Просроченные задачи', 'value' => actor_can($actor, 'tasks.view') ? (int) $db->one("SELECT COUNT(*) AS c FROM tasks WHERE status != 'done' AND due_date < :today", ['today' => app_today()])['c'] : 0, 'href' => '/employees?tab=tasks&filter=overdue'],
            ['label' => 'Проблемы доставки', 'value' => (actor_can($actor, 'deliveries.view') || actor_can($actor, 'deliveries.manage')) ? (int) $db->one("SELECT COUNT(*) AS c FROM deliveries WHERE status = 'problem'")['c'] : 0, 'href' => '/deliveries?status=problem'],
            ['label' => 'Товары ниже минимума', 'value' => actor_can($actor, 'inventory.view') ? (int) $db->one('SELECT COUNT(*) AS c FROM inventory i JOIN products p ON p.id = i.product_id WHERE i.quantity < p.min_stock')['c'] : 0, 'href' => '/inventory?low=1'],
        ];
    }

    private static function customerCounts(Db $db, string $scope, ?int $manager): array
    {
        $params = [];
        if ($manager) {
            $params['manager'] = $manager;
        }
        $base = 'FROM customers c LEFT JOIN subscriptions s ON s.id = (SELECT MAX(s2.id) FROM subscriptions s2 WHERE s2.customer_id = c.id) WHERE 1=1' . $scope;
        $today = app_today();
        $one = fn (string $extra, array $extraParams = []) => (int) $db->one('SELECT COUNT(*) AS c ' . $base . $extra, $params + $extraParams)['c'];
        return [
            'active' => $one(" AND c.status = 'active'"),
            'new_month' => $one(' AND c.registered_at >= :month', ['month' => (new DateTimeImmutable('first day of this month'))->format('Y-m-d')]),
            'renewal' => $one(" AND c.status NOT IN ('paused','lost') AND s.end_date >= :today AND s.end_date <= :plus7", ['today' => $today, 'plus7' => app_date_shift($today, 7)]),
            'ending_today' => $one(" AND c.status NOT IN ('paused','lost') AND s.end_date = :today", ['today' => $today]),
            'ending_1_3' => $one(" AND c.status NOT IN ('paused','lost') AND s.end_date >= :from AND s.end_date <= :to", ['from' => app_date_shift($today, 1), 'to' => app_date_shift($today, 3)]),
            'overdue' => $one(" AND c.status NOT IN ('paused','lost') AND s.end_date < :today", ['today' => $today]),
            'lost' => $one(" AND c.status = 'lost'"),
        ];
    }

    private static function sumPaid(Db $db, string $scope, ?int $manager, string $from, ?string $to): int
    {
        $params = ['from' => $from];
        $sql = "SELECT COALESCE(SUM(p.amount),0) AS c FROM payments p JOIN customers c ON c.id = p.customer_id WHERE p.status = 'paid' AND substr(p.paid_at,1,10) >= :from";
        if ($to) {
            $sql .= ' AND substr(p.paid_at,1,10) <= :to';
            $params['to'] = $to;
        }
        if ($manager) {
            $sql .= ' AND c.manager_id = :manager';
            $params['manager'] = $manager;
        } elseif ($scope !== '') {
            $sql .= $scope;
        }
        return (int) $db->one($sql, $params)['c'];
    }

    private static function avgPaid(Db $db, ?int $manager, string $from, string $to): int
    {
        $params = ['from' => $from, 'to' => $to];
        $sql = "SELECT COALESCE(AVG(amount),0) AS c FROM payments WHERE status = 'paid' AND substr(paid_at,1,10) >= :from AND substr(paid_at,1,10) <= :to";
        if ($manager) {
            $sql .= ' AND manager_id = :manager';
            $params['manager'] = $manager;
        }
        return (int) round((float) $db->one($sql, $params)['c']);
    }

    private static function mrr(Db $db, ?int $manager): int
    {
        $sql = "SELECT s.total_amount, s.days FROM subscriptions s JOIN customers c ON c.id = s.customer_id
                WHERE s.status IN ('active','ending') AND s.days > 0";
        $params = [];
        if ($manager) {
            $sql .= ' AND c.manager_id = :manager';
            $params['manager'] = $manager;
        }
        $sum = 0;
        foreach ($db->all($sql, $params) as $row) {
            $sum += (int) round(((int) $row['total_amount']) * 30 / (int) $row['days']);
        }
        return $sum;
    }

    private static function ltv(Db $db): int
    {
        $row = $db->one("SELECT COUNT(DISTINCT customer_id) AS clients, COALESCE(SUM(amount),0) AS total FROM payments WHERE status = 'paid'");
        $clients = (int) $row['clients'];
        return $clients ? (int) round(((int) $row['total']) / $clients) : 0;
    }

    private static function retention(Db $db): float
    {
        $base = (int) $db->one("SELECT COUNT(*) AS c FROM customers WHERE status != 'new'")['c'];
        $kept = (int) $db->one("SELECT COUNT(*) AS c FROM (SELECT customer_id FROM payments WHERE status = 'paid' GROUP BY customer_id HAVING COUNT(*) > 1) t")['c'];
        return $base ? round($kept / $base * 100, 1) : 0;
    }

    private static function churn(Db $db): float
    {
        $all = (int) $db->one('SELECT COUNT(*) AS c FROM customers')['c'];
        $lost = (int) $db->one("SELECT COUNT(*) AS c FROM customers WHERE status IN ('lost','renewal_required')")['c'];
        return $all ? round($lost / $all * 100, 1) : 0;
    }

    private static function range(string $period): array
    {
        $today = new DateTimeImmutable('today');
        $start = match ($period) {
            'today' => $today,
            'week' => $today->modify('monday this week'),
            'quarter' => $today->setDate((int) $today->format('Y'), (int) (floor(((int) $today->format('n') - 1) / 3) * 3 + 1), 1),
            'year' => $today->setDate((int) $today->format('Y'), 1, 1),
            default => $today->modify('first day of this month'),
        };
        return [$start->format('Y-m-d'), $today->format('Y-m-d')];
    }

    private static function count(Db $db, string $sql, array $params): int
    {
        return (int) $db->one($sql, $params)['c'];
    }
}
