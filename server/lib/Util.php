<?php
declare(strict_types=1);

function app_now(): string
{
    return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
}

function app_today(): string
{
    return (new DateTimeImmutable('today'))->format('Y-m-d');
}

function app_date_shift(string $date, int $days): string
{
    return (new DateTimeImmutable($date))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

function days_left(?string $end, ?string $today = null): ?int
{
    if (!$end) {
        return null;
    }
    $today = $today ?? app_today();
    $a = new DateTimeImmutable($today);
    $b = new DateTimeImmutable($end);
    return (int) $a->diff($b)->format('%r%a');
}

function phone_digits(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) === 11 && str_starts_with($digits, '8')) {
        $digits = '7' . substr($digits, 1);
    }
    if (strlen($digits) === 10) {
        $digits = '7' . $digits;
    }
    return $digits;
}

function phone_format(string $digits): string
{
    if (strlen($digits) !== 11) {
        return $digits;
    }
    return sprintf(
        '+%s %s %s %s %s',
        $digits[0],
        substr($digits, 1, 3),
        substr($digits, 4, 3),
        substr($digits, 7, 2),
        substr($digits, 9, 2),
    );
}

function money_int(mixed $value): int
{
    if ($value === null || $value === '') {
        return 0;
    }
    if (!is_numeric($value)) {
        throw new HttpError(422, 'Сумма должна быть числом');
    }
    return (int) round((float) $value);
}

function render_template(string $body, array $vars): string
{
    return (string) preg_replace_callback('/\{([a-z0-9_]+)\}/', static function (array $m) use ($vars): string {
        return (string) ($vars[$m[1]] ?? '');
    }, $body);
}

function setting(Db $db, string $name, ?string $default = null): ?string
{
    $row = $db->one('SELECT value FROM settings WHERE name = :name', ['name' => $name]);
    return $row['value'] ?? $default;
}

function set_setting(Db $db, string $name, string $value): void
{
    $exists = $db->one('SELECT name FROM settings WHERE name = :name', ['name' => $name]);
    if ($exists) {
        $db->exec('UPDATE settings SET value = :value WHERE name = :name', ['value' => $value, 'name' => $name]);
        return;
    }
    $db->insert('INSERT INTO settings (name, value) VALUES (:name, :value)', ['name' => $name, 'value' => $value]);
}

function audit_change(Db $db, ?int $userId, string $entity, ?int $id, string $action, ?string $field, mixed $old, mixed $new): void
{
    $db->insert(
        'INSERT INTO audit_logs (user_id, entity_type, entity_id, action, field, old_value, new_value, created_at)
         VALUES (:user_id, :entity, :id, :action, :field, :old, :new, :created)',
        [
            'user_id' => $userId,
            'entity' => $entity,
            'id' => $id,
            'action' => $action,
            'field' => $field,
            'old' => $old === null ? null : (string) $old,
            'new' => $new === null ? null : (string) $new,
            'created' => app_now(),
        ],
    );
}

function audit_fields(Db $db, ?int $userId, string $entity, int $id, array $before, array $after): void
{
    foreach ($after as $field => $value) {
        $old = $before[$field] ?? null;
        if ((string) $old !== (string) $value) {
            audit_change($db, $userId, $entity, $id, 'update', $field, $old, $value);
        }
    }
}

function actor_can(array $actor, string $permission): bool
{
    $perms = $actor['permissions'] ?? [];
    return in_array('*', $perms, true) || in_array($permission, $perms, true);
}

function actor_require(array $actor, string $permission): void
{
    if (!actor_can($actor, $permission)) {
        throw new HttpError(403, 'Недостаточно прав', 'forbidden');
    }
}

function clamp_page(mixed $page): int
{
    return max(1, (int) $page);
}

function clamp_per_page(mixed $value, int $fallback = 20): int
{
    $n = (int) ($value ?: $fallback);
    return min(100, max(1, $n));
}
