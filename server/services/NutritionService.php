<?php
declare(strict_types=1);

final class NutritionService
{
    public const LINES = ['Премиум', 'Стандарт'];
    public const STATUSES = ['active', 'ended', 'paused'];
    public const DELIVERY_TIMES = ['утро', 'вечер'];
    public const TARIFF_DAYS = [7, 6, 5, 4];

    public static function list(Db $db, array $actor, array $query): array
    {
        actor_require($actor, 'customers.view');
        [$scope, $params] = CrmService::customerScope($actor, 'c');
        $where = ' WHERE 1=1' . $scope . self::filterSql($query, $params);
        if (!empty($query['q'])) {
            $q = trim((string) $query['q']);
            $where .= ' AND (cs.full_name LIKE :q OR c.full_name LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        $from = 'FROM client_subscriptions cs
            JOIN customers c ON c.id = cs.customer_id' . $where;
        $page = clamp_page($query['page'] ?? 1);
        $per = clamp_per_page($query['per_page'] ?? 20);
        $total = (int) $db->one('SELECT COUNT(*) AS c ' . $from, $params)['c'];
        $rows = $db->all(
            'SELECT cs.*, c.full_name AS customer_name, c.phone, c.vip
             ' . $from . ' ORDER BY cs.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per),
            $params,
        );
        foreach ($rows as &$row) {
            $row = self::decorate($row);
        }
        return [
            'data' => $rows,
            'page' => $page,
            'per_page' => $per,
            'total' => $total,
            'segments' => self::segments($db, $actor),
            'view' => 'nutrition',
        ];
    }

    public static function segments(Db $db, array $actor): array
    {
        [$scope, $params] = CrmService::customerScope($actor, 'c');
        $from = 'FROM client_subscriptions cs JOIN customers c ON c.id = cs.customer_id WHERE 1=1' . $scope;
        $count = static function (string $extra, array $extraParams = []) use ($db, $from, $params): int {
            return (int) $db->one('SELECT COUNT(*) AS c ' . $from . $extra, array_merge($params, $extraParams))['c'];
        };
        $linePremium = $count(" AND cs.line = 'Премиум'");
        $lineStandard = $count(" AND cs.line = 'Стандарт'");
        $byTariff = [];
        foreach (self::TARIFF_DAYS as $days) {
            $byTariff[(string) $days] = $count(' AND cs.tariff_days = :td', ['td' => $days]);
        }
        $byTariff['none'] = $count(' AND cs.tariff_days IS NULL');
        return [
            'total' => $count(''),
            'line' => ['Премиум' => $linePremium, 'Стандарт' => $lineStandard],
            'tariff' => $byTariff,
            'status' => [
                'active' => $count(" AND cs.status = 'active'"),
                'ended' => $count(" AND cs.status = 'ended'"),
                'paused' => $count(" AND cs.status = 'paused'"),
                'undefined_tariff' => $count(' AND cs.tariff_days IS NULL'),
            ],
            'needs_review' => $count(' AND cs.needs_review = 1'),
            'ending_3' => $count(
                " AND cs.status = 'active' AND cs.end_date IS NOT NULL AND cs.end_date >= :today AND cs.end_date <= :plus3",
                ['today' => app_today(), 'plus3' => app_date_shift(app_today(), 3)],
            ),
        ];
    }

    public static function dashboardStats(Db $db, array $actor): array
    {
        if (!actor_can($actor, 'customers.view')) {
            return [
                'lines' => ['Премиум' => 0, 'Стандарт' => 0],
                'tariffs' => ['7' => 0, '6' => 0, '5' => 0, '4' => 0],
                'ending_7' => 0,
                'paused' => 0,
                'active_total' => 0,
            ];
        }
        [$scope, $params] = CrmService::customerScope($actor, 'c');
        $from = "FROM client_subscriptions cs JOIN customers c ON c.id = cs.customer_id WHERE cs.status = 'active'" . $scope;
        $count = static function (string $extra, array $extraParams = []) use ($db, $from, $params): int {
            return (int) $db->one('SELECT COUNT(*) AS c ' . $from . $extra, array_merge($params, $extraParams))['c'];
        };
        $tariffs = [];
        foreach (self::TARIFF_DAYS as $days) {
            $tariffs[(string) $days] = $count(' AND cs.tariff_days = :td', ['td' => $days]);
        }
        return [
            'lines' => [
                'Премиум' => $count(" AND cs.line = 'Премиум'"),
                'Стандарт' => $count(" AND cs.line = 'Стандарт'"),
            ],
            'tariffs' => $tariffs,
            'ending_7' => $count(
                ' AND cs.end_date IS NOT NULL AND cs.end_date >= :today AND cs.end_date <= :plus7',
                ['today' => app_today(), 'plus7' => app_date_shift(app_today(), 7)],
            ),
            'paused' => (int) $db->one(
                'SELECT COUNT(*) AS c FROM client_subscriptions cs JOIN customers c ON c.id = cs.customer_id WHERE cs.status = :st' . $scope,
                array_merge($params, ['st' => 'paused']),
            )['c'],
            'active_total' => $count(''),
        ];
    }

    public static function forCustomer(Db $db, int $customerId): array
    {
        $rows = $db->all(
            'SELECT * FROM client_subscriptions WHERE customer_id = :id ORDER BY id DESC',
            ['id' => $customerId],
        );
        foreach ($rows as &$row) {
            $row = self::decorate($row);
        }
        return $rows;
    }

    public static function create(Db $db, array $actor, int $customerId, array $input): array
    {
        actor_require($actor, 'customers.manage');
        BillingService::customer($db, $actor, $customerId);
        $payload = self::normalize($input, true);
        $now = app_now();
        $id = $db->insert(
            'INSERT INTO client_subscriptions (
                customer_id, full_name, line, section, tariff_days, pack_days, kcal, ration_no, diet,
                delivery_time, period_start, period_end, end_date, pauses, status, tariff_days_ref,
                flag_mark, notes, source_row, source_text, needs_review, review_reasons,
                import_batch_id, created_at, updated_at
             ) VALUES (
                :customer_id, :full_name, :line, :section, :tariff_days, :pack_days, :kcal, :ration_no, :diet,
                :delivery_time, :period_start, :period_end, :end_date, :pauses, :status, :tariff_days_ref,
                :flag_mark, :notes, :source_row, :source_text, :needs_review, :review_reasons,
                :import_batch_id, :created_at, :updated_at
             )',
            array_merge($payload, [
                'customer_id' => $customerId,
                'import_batch_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
        );
        audit_change($db, $actor['id'], 'client_subscriptions', $id, 'create', 'full_name', null, $payload['full_name']);
        return self::decorate($db->one('SELECT * FROM client_subscriptions WHERE id = :id', ['id' => $id]) ?? []);
    }

    public static function update(Db $db, array $actor, int $customerId, int $rationId, array $input): array
    {
        actor_require($actor, 'customers.manage');
        BillingService::customer($db, $actor, $customerId);
        $before = $db->one(
            'SELECT * FROM client_subscriptions WHERE id = :id AND customer_id = :cid',
            ['id' => $rationId, 'cid' => $customerId],
        );
        if (!$before) {
            throw new HttpError(404, 'Подписка питания не найдена');
        }
        $payload = self::normalize(array_merge($before, $input), false);
        $sets = [];
        $params = ['id' => $rationId, 'updated_at' => app_now()];
        foreach ($payload as $key => $value) {
            $sets[] = $key . ' = :' . $key;
            $params[$key] = $value;
        }
        $sets[] = 'updated_at = :updated_at';
        $db->exec('UPDATE client_subscriptions SET ' . implode(', ', $sets) . ' WHERE id = :id', $params);
        audit_fields($db, $actor['id'], 'client_subscriptions', $rationId, $before, $payload);
        return self::decorate($db->one('SELECT * FROM client_subscriptions WHERE id = :id', ['id' => $rationId]) ?? []);
    }

    public static function decorate(array $row): array
    {
        if (!$row) {
            return $row;
        }
        $row['needs_review'] = (int) ($row['needs_review'] ?? 0);
        $row['tariff_days'] = $row['tariff_days'] === null || $row['tariff_days'] === '' ? null : (int) $row['tariff_days'];
        $row['tariff_days_ref'] = $row['tariff_days_ref'] === null || $row['tariff_days_ref'] === '' ? null : (int) $row['tariff_days_ref'];
        $row['expired_active'] = ($row['status'] ?? '') === 'active'
            && !empty($row['end_date'])
            && (string) $row['end_date'] < app_today();
        return $row;
    }

    public static function normalize(array $input, bool $requireName): array
    {
        $fullName = trim((string) ($input['full_name'] ?? ''));
        if ($requireName && $fullName === '') {
            throw new HttpError(422, 'Укажите ФИО подписки');
        }
        $line = trim((string) ($input['line'] ?? ''));
        if (!in_array($line, self::LINES, true)) {
            throw new HttpError(422, 'Линейка должна быть Премиум или Стандарт');
        }
        $status = trim((string) ($input['status'] ?? 'active'));
        if (!in_array($status, self::STATUSES, true)) {
            throw new HttpError(422, 'Неизвестный статус подписки');
        }
        $delivery = $input['delivery_time'] ?? null;
        $delivery = $delivery === '' || $delivery === null ? null : trim((string) $delivery);
        if ($delivery !== null && !in_array($delivery, self::DELIVERY_TIMES, true)) {
            throw new HttpError(422, 'Время доставки: утро или вечер');
        }
        $tariff = self::nullableInt($input['tariff_days'] ?? null);
        if ($tariff !== null && !in_array($tariff, self::TARIFF_DAYS, true)) {
            throw new HttpError(422, 'Тариф: 7, 6, 5 или 4 дня');
        }
        return [
            'full_name' => $fullName !== '' ? $fullName : (string) ($input['full_name'] ?? ''),
            'line' => $line,
            'section' => self::nullableString($input['section'] ?? null),
            'tariff_days' => $tariff,
            'pack_days' => self::nullableString($input['pack_days'] ?? null),
            'kcal' => self::nullableString($input['kcal'] ?? null),
            'ration_no' => self::nullableString($input['ration_no'] ?? null),
            'diet' => self::nullableString($input['diet'] ?? null),
            'delivery_time' => $delivery,
            'period_start' => self::nullableDate($input['period_start'] ?? null),
            'period_end' => self::nullableDate($input['period_end'] ?? null),
            'end_date' => self::nullableDate($input['end_date'] ?? null),
            'pauses' => self::nullableString($input['pauses'] ?? null),
            'status' => $status,
            'tariff_days_ref' => self::nullableInt($input['tariff_days_ref'] ?? null),
            'flag_mark' => self::nullableString($input['flag_mark'] ?? null, 5),
            'notes' => self::nullableString($input['notes'] ?? null),
            'source_row' => self::nullableInt($input['source_row'] ?? null),
            'source_text' => self::nullableString($input['source_text'] ?? null),
            'needs_review' => !empty($input['needs_review']) ? 1 : 0,
            'review_reasons' => self::nullableString($input['review_reasons'] ?? null),
        ];
    }

    private static function filterSql(array $query, array &$params): string
    {
        $sql = '';
        if (!empty($query['line']) && in_array($query['line'], self::LINES, true)) {
            $sql .= ' AND cs.line = :line';
            $params['line'] = $query['line'];
        }
        if (isset($query['tariff']) && $query['tariff'] !== '' && $query['tariff'] !== null) {
            if ($query['tariff'] === 'none' || $query['tariff'] === 'undefined') {
                $sql .= ' AND cs.tariff_days IS NULL';
            } else {
                $sql .= ' AND cs.tariff_days = :tariff';
                $params['tariff'] = (int) $query['tariff'];
            }
        }
        if (!empty($query['status'])) {
            if ($query['status'] === 'undefined' || $query['status'] === 'undefined_tariff') {
                $sql .= ' AND cs.tariff_days IS NULL';
            } elseif (in_array($query['status'], self::STATUSES, true)) {
                $sql .= ' AND cs.status = :status';
                $params['status'] = $query['status'];
            }
        }
        if (!empty($query['delivery_time']) && in_array($query['delivery_time'], self::DELIVERY_TIMES, true)) {
            $sql .= ' AND cs.delivery_time = :delivery_time';
            $params['delivery_time'] = $query['delivery_time'];
        }
        if (!empty($query['diet'])) {
            $sql .= ' AND cs.diet LIKE :diet';
            $params['diet'] = '%' . trim((string) $query['diet']) . '%';
        }
        if (!empty($query['kcal'])) {
            $sql .= ' AND cs.kcal LIKE :kcal';
            $params['kcal'] = '%' . trim((string) $query['kcal']) . '%';
        }
        if (!empty($query['ending_3'])) {
            $sql .= ' AND cs.status = \'active\' AND cs.end_date IS NOT NULL AND cs.end_date >= :ending_from AND cs.end_date <= :ending_to';
            $params['ending_from'] = app_today();
            $params['ending_to'] = app_date_shift(app_today(), 3);
        }
        if (!empty($query['needs_review'])) {
            $sql .= ' AND cs.needs_review = 1';
        }
        return $sql;
    }

    private static function nullableString(mixed $value, ?int $max = null): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        if ($max !== null && mb_strlen($s) > $max) {
            $s = mb_substr($s, 0, $max);
        }
        return $s;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw new HttpError(422, 'Ожидалось число');
        }
        return (int) $value;
    }

    private static function nullableDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $s = trim((string) $value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            throw new HttpError(422, 'Дата должна быть в формате YYYY-MM-DD');
        }
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $s);
        if (!$dt || $dt->format('Y-m-d') !== $s) {
            throw new HttpError(422, 'Некорректная дата');
        }
        return $s;
    }
}
