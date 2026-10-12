<?php
declare(strict_types=1);

final class ToolRegistry
{
    private const READ = [
        'get_dashboard_stats', 'get_sales_funnel', 'get_customers', 'get_customer', 'search_customers',
        'get_expiring_subscriptions', 'get_overdue_subscriptions', 'get_subscription', 'get_payments',
        'get_revenue', 'get_sales_by_manager', 'get_delivery_schedule', 'get_inventory', 'get_low_stock_products',
        'get_employee', 'get_tasks', 'get_overdue_tasks', 'get_analytics', 'get_customer_history', 'search_whatsapp_messages',
    ];

    private const ACTIONS = [
        'create_task', 'send_whatsapp_message', 'create_payment_link', 'create_discount', 'update_customer', 'extend_subscription',
    ];

    public function definitions(array $actor): array
    {
        $tools = [];
        foreach (self::READ as $name) {
            if ($this->allowed($actor, $name)) {
                $tools[] = $this->schema($name);
            }
        }
        foreach (self::ACTIONS as $name) {
            $tools[] = $this->schema($name);
        }
        return $tools;
    }

    public function call(string $name, array $args, array $actor, Db $db): array
    {
        if (in_array($name, self::ACTIONS, true)) {
            return [
                'ok' => false,
                'mode' => 'read_only',
                'confirmation_required' => true,
                'preview' => 'Действие «' . $name . '» не выполнено. Включён режим только чтения.',
            ];
        }
        if (!$this->allowed($actor, $name)) {
            return ['ok' => false, 'error' => 'forbidden'];
        }
        try {
            return match ($name) {
                'get_dashboard_stats' => InsightService::dashboard($db, $actor),
                'get_sales_funnel' => $this->funnel($db, $actor),
                'get_customers' => $this->customers($db, $actor, (string) ($args['filter'] ?? 'all')),
                'get_customer', 'get_customer_history' => $this->customer($db, $actor, (int) ($args['customer_id'] ?? 0)),
                'search_customers' => $this->searchCustomers($db, $actor, (string) ($args['query'] ?? '')),
                'get_expiring_subscriptions' => $this->expiring($db, $actor, (int) ($args['days'] ?? 3)),
                'get_overdue_subscriptions' => $this->customers($db, $actor, 'overdue'),
                'get_subscription' => $this->subscription($db, $actor, (int) ($args['subscription_id'] ?? 0)),
                'get_payments' => $this->payments($db, $actor),
                'get_revenue' => $this->revenue($db, $actor, (string) ($args['period'] ?? 'month')),
                'get_sales_by_manager' => $this->managers($db, $actor),
                'get_delivery_schedule' => OpsService::deliveries($db, $actor, ['date' => (string) ($args['date'] ?? app_today())]),
                'get_inventory' => $this->stock($db, $actor, false),
                'get_low_stock_products' => $this->stock($db, $actor, true),
                'get_employee' => $this->employee($db, $actor, (int) ($args['employee_id'] ?? 0)),
                'get_tasks' => PeopleService::tasks($db, $actor, ['filter' => (string) ($args['filter'] ?? 'today')]),
                'get_overdue_tasks' => PeopleService::tasks($db, $actor, ['filter' => 'overdue']),
                'get_analytics' => InsightService::analytics($db, $actor, (string) ($args['period'] ?? 'month')),
                'search_whatsapp_messages' => $this->whatsapp($db, $actor, (string) ($args['query'] ?? '')),
                default => ['ok' => false, 'error' => 'unknown_tool'],
            };
        } catch (HttpError $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function allowed(array $actor, string $name): bool
    {
        $need = match ($name) {
            'get_dashboard_stats' => 'dashboard.view',
            'get_sales_funnel' => 'leads.view',
            'get_customers', 'get_customer', 'search_customers', 'get_customer_history', 'get_expiring_subscriptions', 'get_overdue_subscriptions' => 'customers.view',
            'get_subscription' => 'subscriptions.view',
            'get_payments', 'get_revenue' => 'payments.view',
            'get_sales_by_manager', 'get_analytics' => 'analytics.view',
            'get_delivery_schedule' => 'deliveries.view',
            'get_inventory', 'get_low_stock_products' => 'inventory.view',
            'get_employee' => 'employees.view',
            'get_tasks', 'get_overdue_tasks' => 'tasks.view',
            'search_whatsapp_messages' => 'whatsapp.view',
            default => 'ai.use',
        };
        if ($name === 'get_delivery_schedule' && (actor_can($actor, 'deliveries.own') || actor_can($actor, 'deliveries.manage'))) {
            return true;
        }
        if ($name === 'get_revenue' && !actor_can($actor, 'payments.view') && !actor_can($actor, 'finance.view')) {
            return false;
        }
        return actor_can($actor, $need) || actor_can($actor, 'finance.view');
    }

    private function schema(string $name): array
    {
        $props = match ($name) {
            'get_customers' => ['filter' => ['type' => 'string']],
            'get_customer', 'get_customer_history' => ['customer_id' => ['type' => 'integer']],
            'search_customers', 'search_whatsapp_messages' => ['query' => ['type' => 'string']],
            'get_expiring_subscriptions' => ['days' => ['type' => 'integer']],
            'get_subscription' => ['subscription_id' => ['type' => 'integer']],
            'get_revenue', 'get_analytics', 'get_sales_by_manager' => ['period' => ['type' => 'string']],
            'get_delivery_schedule' => ['date' => ['type' => 'string']],
            'get_employee' => ['employee_id' => ['type' => 'integer']],
            'get_tasks' => ['filter' => ['type' => 'string']],
            default => [],
        };
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $name,
                'parameters' => [
                    'type' => 'object',
                    'properties' => $props === [] ? new stdClass() : $props,
                ],
            ],
        ];
    }

    private function scope(array $actor): array
    {
        if (actor_can($actor, 'customers.view_all') || actor_can($actor, 'finance.view')) {
            return ['', []];
        }
        return [' AND c.manager_id = :manager', ['manager' => $actor['employee_id']]];
    }

    private function customers(Db $db, array $actor, string $filter): array
    {
        $list = CrmService::listCustomers($db, $actor, ['filter' => $filter, 'per_page' => 15]);
        return [
            'total' => $list['total'],
            'summary' => $list['summary'],
            'customers' => array_map(fn ($row) => [
                'id' => (int) $row['id'],
                'name' => $row['full_name'],
                'phone' => $row['phone'],
                'status' => $row['status'],
                'program' => $row['program_name'],
                'end_date' => $row['end_date'],
                'days_left' => $row['days_left'],
                'amount' => $row['total_amount'],
                'manager' => $row['manager_name'],
            ], $list['data']),
        ];
    }

    private function customer(Db $db, array $actor, int $id): array
    {
        if ($id <= 0) {
            return ['ok' => false, 'error' => 'Нужен customer_id'];
        }
        $card = CrmService::getCustomer($db, $actor, $id);
        return [
            'id' => (int) $card['customer']['id'],
            'name' => $card['customer']['full_name'],
            'phone' => $card['customer']['phone'],
            'status' => $card['customer']['status'],
            'city' => $card['customer']['city'],
            'manager' => $card['manager']['full_name'] ?? null,
            'ltv' => $card['stats']['ltv'],
            'avg_check' => $card['stats']['avg_check'],
            'subscription' => $card['current'],
            'payments' => array_slice($card['payments'], 0, 5),
            'last_contact_at' => $card['customer']['last_contact_at'],
        ];
    }

    private function searchCustomers(Db $db, array $actor, string $query): array
    {
        $list = CrmService::listCustomers($db, $actor, ['q' => $query, 'per_page' => 10]);
        return [
            'total' => $list['total'],
            'customers' => array_map(fn ($row) => [
                'id' => (int) $row['id'],
                'name' => $row['full_name'],
                'phone' => $row['phone'],
                'status' => $row['status'],
            ], $list['data']),
        ];
    }

    private function expiring(Db $db, array $actor, int $days): array
    {
        $days = min(30, max(0, $days));
        [$scope, $params] = $this->scope($actor);
        $params['from'] = app_today();
        $params['to'] = app_date_shift(app_today(), $days);
        $rows = $db->all(
            "SELECT c.id, c.full_name, c.phone, s.end_date, s.total_amount, p.name AS program
             FROM customers c
             JOIN subscriptions s ON s.id = (SELECT MAX(id) FROM subscriptions s2 WHERE s2.customer_id = c.id)
             JOIN meal_programs p ON p.id = s.program_id
             WHERE c.status NOT IN ('paused','lost') AND s.end_date >= :from AND s.end_date <= :to" . $scope . ' LIMIT 20',
            $params,
        );
        return ['days' => $days, 'count' => count($rows), 'customers' => $rows];
    }

    private function subscription(Db $db, array $actor, int $id): array
    {
        $sub = $db->one('SELECT s.*, c.full_name, c.manager_id FROM subscriptions s JOIN customers c ON c.id = s.customer_id WHERE s.id = :id', ['id' => $id]);
        if (!$sub) {
            return ['ok' => false, 'error' => 'Подписка не найдена'];
        }
        BillingService::customer($db, $actor, (int) $sub['customer_id']);
        $sub['days_left'] = days_left($sub['end_date']);
        return $sub;
    }

    private function payments(Db $db, array $actor): array
    {
        $list = BillingService::listPayments($db, $actor, ['per_page' => 15]);
        return ['total' => $list['total'], 'payments' => $list['data']];
    }

    private function revenue(Db $db, array $actor, string $period): array
    {
        if (!actor_can($actor, 'finance.view') && !actor_can($actor, 'analytics.view_all')) {
            $analytics = InsightService::analytics($db, $actor, $period);
            return ['scope' => 'own', 'sales' => $analytics['sales']];
        }
        return InsightService::finance($db, $actor, $period);
    }

    private function managers(Db $db, array $actor): array
    {
        $analytics = InsightService::analytics($db, $actor, 'month');
        return ['managers' => $analytics['managers'], 'sales' => $analytics['sales']];
    }

    private function funnel(Db $db, array $actor): array
    {
        $rows = CrmService::listLeads($db, $actor, [])['data'];
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['stage']] = ($counts[$row['stage']] ?? 0) + 1;
        }
        return ['total' => count($rows), 'stages' => $counts];
    }

    private function stock(Db $db, array $actor, bool $low): array
    {
        $list = OpsService::inventory($db, $actor, $low ? ['low' => 1, 'per_page' => 20] : ['per_page' => 20]);
        return ['total' => $list['total'], 'products' => array_map(fn ($row) => [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'sku' => $row['sku'],
            'quantity' => (int) $row['quantity'],
            'min_stock' => (int) $row['min_stock'],
        ], $list['data'])];
    }

    private function employee(Db $db, array $actor, int $id): array
    {
        $list = PeopleService::employees($db, $actor)['data'];
        foreach ($list as $row) {
            if ((int) $row['id'] === $id) {
                unset($row['salary']);
                return $row;
            }
        }
        return ['ok' => false, 'error' => 'Сотрудник не найден'];
    }

    private function whatsapp(Db $db, array $actor, string $query): array
    {
        $like = '%' . $query . '%';
        return ['messages' => $db->all(
            'SELECT m.id, m.body, m.created_at, w.phone, c.full_name
             FROM whatsapp_messages m
             JOIN whatsapp_conversations w ON w.id = m.conversation_id
             LEFT JOIN customers c ON c.id = w.customer_id
             WHERE m.body LIKE :q ORDER BY m.id DESC LIMIT 10',
            ['q' => $like],
        )];
    }
}
