<?php
declare(strict_types=1);

final class AutomationService
{
    public static function runDaily(Db $db, array $config): array
    {
        self::syncStatuses($db);
        $created = 0;
        $rules = $db->all('SELECT * FROM automation_rules WHERE enabled = 1 AND trigger_code = :code', ['code' => 'subscription_days']);
        $latest = self::latestSubscriptions($db);
        foreach ($rules as $rule) {
            $cond = $db->one('SELECT value FROM automation_conditions WHERE rule_id = :id AND field = :field', [
                'id' => $rule['id'],
                'field' => 'days',
            ]);
            $target = (int) ($cond['value'] ?? 0);
            $actions = $db->all('SELECT * FROM automation_actions WHERE rule_id = :id', ['id' => $rule['id']]);
            foreach ($latest as $sub) {
                if (in_array($sub['status'], ['paused', 'cancelled', 'renewed'], true)) {
                    continue;
                }
                if (in_array($sub['customer_status'], ['paused', 'lost'], true)) {
                    continue;
                }
                $left = days_left($sub['end_date']);
                $match = $target < 0 ? $left < 0 : $left === $target;
                if (!$match || !self::once($db, (int) $rule['id'], 'subscription', (int) $sub['id'], (string) $sub['end_date'])) {
                    continue;
                }
                self::runActions($db, $config, $actions, $sub);
                $created++;
            }
        }
        self::runStock($db, $config);
        set_setting($db, 'retention_ran_on', app_today());
        return ['processed' => $created];
    }

    public static function ensureToday(Db $db, array $config): void
    {
        if (setting($db, 'retention_ran_on') === app_today()) {
            return;
        }
        self::runDaily($db, $config);
    }

    public static function emit(Db $db, array $config, string $trigger, array $context): void
    {
        $rules = $db->all('SELECT * FROM automation_rules WHERE enabled = 1 AND trigger_code = :code', ['code' => $trigger]);
        foreach ($rules as $rule) {
            $entityId = (int) ($context['entity_id'] ?? 0);
            if ($entityId && !self::once($db, (int) $rule['id'], $trigger, $entityId, (string) $entityId)) {
                continue;
            }
            $actions = $db->all('SELECT * FROM automation_actions WHERE rule_id = :id', ['id' => $rule['id']]);
            foreach ($actions as $action) {
                if ($action['action_code'] === 'notify' && !empty($context['employee_id'])) {
                    CommService::notifyEmployee(
                        $db,
                        $config,
                        (int) $context['employee_id'],
                        $action['param'] ?: 'important',
                        (string) ($context['title'] ?? 'Событие'),
                        (string) ($context['body'] ?? ''),
                        $context['entity_type'] ?? null,
                        $entityId ?: null,
                        $context['link'] ?? null,
                    );
                }
            }
        }
    }

    public static function syncStatuses(Db $db): void
    {
        foreach (self::latestSubscriptions($db) as $sub) {
            if (in_array($sub['status'], ['paused', 'cancelled', 'renewed'], true) || $sub['customer_status'] === 'lost') {
                continue;
            }
            $left = days_left($sub['end_date']);
            $subStatus = $left < 0 ? 'awaiting_renewal' : ($left <= 3 ? 'ending' : 'active');
            $customerStatus = $left < 0 ? 'renewal_required' : ($sub['customer_status'] === 'new' ? 'new' : 'active');
            if ($sub['customer_status'] === 'paused') {
                continue;
            }
            if ($sub['status'] !== $subStatus) {
                $db->exec('UPDATE subscriptions SET status = :status WHERE id = :id', ['status' => $subStatus, 'id' => $sub['id']]);
            }
            if ($sub['customer_status'] !== $customerStatus) {
                $db->exec('UPDATE customers SET status = :status WHERE id = :id', ['status' => $customerStatus, 'id' => $sub['customer_id']]);
            }
        }
    }

    private static function runStock(Db $db, array $config): void
    {
        $rule = $db->one('SELECT * FROM automation_rules WHERE trigger_code = :code AND enabled = 1', ['code' => 'stock_below_min']);
        if (!$rule) {
            return;
        }
        $db->exec(
            'DELETE FROM automation_runs WHERE rule_id = :rule AND entity_type = :type AND entity_id IN (
                SELECT p.id FROM products p JOIN inventory i ON i.product_id = p.id WHERE i.quantity >= p.min_stock
            )',
            ['rule' => $rule['id'], 'type' => 'product'],
        );
        $rows = $db->all(
            'SELECT p.id, p.name, p.min_stock, i.quantity FROM products p
             JOIN inventory i ON i.product_id = p.id
             WHERE i.quantity < p.min_stock',
        );
        foreach ($rows as $row) {
            if (!self::once($db, (int) $rule['id'], 'product', (int) $row['id'], 'low')) {
                continue;
            }
            CommService::notifyPermission(
                $db,
                $config,
                'inventory.manage',
                'important',
                'Низкий остаток: ' . $row['name'],
                'На складе ' . $row['quantity'] . ' при минимуме ' . $row['min_stock'] . '.',
                'product',
                (int) $row['id'],
                '/inventory?low=1',
            );
        }
    }

    private static function runActions(Db $db, array $config, array $actions, array $sub): void
    {
        $left = days_left($sub['end_date']);
        $name = $sub['full_name'];
        foreach ($actions as $action) {
            $code = $action['action_code'];
            if ($code === 'notify') {
                $level = $action['param'] ?: 'info';
                CommService::notifyEmployee(
                    $db,
                    $config,
                    $sub['manager_id'] ? (int) $sub['manager_id'] : null,
                    $level,
                    'Подписка: ' . $name,
                    $left < 0
                        ? 'Подписка закончилась. Клиента нужно вернуть на продление.'
                        : 'До окончания программы осталось ' . $left . ' дн.',
                    'customer',
                    (int) $sub['customer_id'],
                    '/customers/' . $sub['customer_id'],
                );
            }
            if ($code === 'task') {
                $open = $db->one(
                    'SELECT id FROM tasks WHERE customer_id = :customer AND status != :done AND title = :title',
                    ['customer' => $sub['customer_id'], 'done' => 'done', 'title' => 'Позвонить клиенту для продления'],
                );
                if (!$open) {
                    $db->insert(
                        'INSERT INTO tasks (title, description, assignee_id, customer_id, due_date, priority, status, created_at)
                         VALUES (:title, :description, :assignee, :customer, :due, :priority, :status, :created)',
                        [
                            'title' => 'Позвонить клиенту для продления',
                            'description' => 'Программа заканчивается ' . $sub['end_date'] . '.',
                            'assignee' => $sub['manager_id'],
                            'customer' => $sub['customer_id'],
                            'due' => app_today(),
                            'priority' => 'high',
                            'status' => 'open',
                            'created' => app_now(),
                        ],
                    );
                }
            }
            if ($code === 'draft_message' && !empty($action['param'])) {
                try {
                    CommService::queueTemplate($db, $config, (int) $sub['customer_id'], (string) $action['param'], null);
                } catch (HttpError) {
                    // Шаблон могли удалить. Правило не должно ронять ежедневную проверку.
                }
            }
        }
    }

    private static function once(Db $db, int $ruleId, string $entity, int $entityId, string $cycle): bool
    {
        $exists = $db->one(
            'SELECT id FROM automation_runs WHERE rule_id = :rule AND entity_type = :type AND entity_id = :id AND cycle_key = :cycle',
            ['rule' => $ruleId, 'type' => $entity, 'id' => $entityId, 'cycle' => $cycle],
        );
        if ($exists) {
            return false;
        }
        try {
            $db->insert(
                'INSERT INTO automation_runs (rule_id, entity_type, entity_id, cycle_key, created_at)
                 VALUES (:rule, :type, :id, :cycle, :created)',
                ['rule' => $ruleId, 'type' => $entity, 'id' => $entityId, 'cycle' => $cycle, 'created' => app_now()],
            );
        } catch (PDOException) {
            return false;
        }
        return true;
    }

    private static function latestSubscriptions(Db $db): array
    {
        return $db->all(
            'SELECT s.*, c.full_name, c.status AS customer_status, c.manager_id, c.phone
             FROM subscriptions s
             JOIN customers c ON c.id = s.customer_id
             WHERE s.id = (SELECT MAX(s2.id) FROM subscriptions s2 WHERE s2.customer_id = s.customer_id)',
        );
    }
}
