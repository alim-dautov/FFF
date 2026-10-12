<?php
declare(strict_types=1);

final class AuthService
{
    public static function actorFromUser(Db $db, array $user): array
    {
        $perms = $db->all(
            'SELECT p.code FROM permissions p
             JOIN role_permissions rp ON rp.permission_id = p.id
             WHERE rp.role_id = :role',
            ['role' => $user['role_id']],
        );
        $role = $db->one('SELECT code, name FROM roles WHERE id = :id', ['id' => $user['role_id']]);
        $name = $user['email'];
        if (!empty($user['employee_id'])) {
            $employee = $db->one('SELECT full_name FROM employees WHERE id = :id', ['id' => $user['employee_id']]);
            $name = $employee['full_name'] ?? $name;
        }
        return [
            'id' => (int) $user['id'],
            'employee_id' => !empty($user['employee_id']) ? (int) $user['employee_id'] : null,
            'email' => $user['email'],
            'full_name' => $name,
            'role' => $role['code'] ?? '',
            'role_name' => $role['name'] ?? '',
            'status' => $user['status'],
            'permissions' => array_column($perms, 'code'),
        ];
    }

    public static function attempt(Db $db, string $email, string $password, string $ip): array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '' || $password === '') {
            throw new HttpError(422, 'Введите email и пароль');
        }
        $cutoff = (new DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s');
        $fails = $db->one(
            'SELECT COUNT(*) AS c FROM login_attempts
             WHERE email = :email AND ip = :ip AND success = 0 AND created_at >= :cutoff',
            ['email' => $email, 'ip' => $ip, 'cutoff' => $cutoff],
        );
        if ((int) ($fails['c'] ?? 0) >= 8) {
            throw new HttpError(429, 'Слишком много попыток. Подождите 10 минут.', 'rate_limit');
        }
        $user = $db->one('SELECT * FROM users WHERE email = :email', ['email' => $email]);
        $ok = $user && password_verify($password, $user['password_hash']) && $user['status'] === 'active';
        $db->insert(
            'INSERT INTO login_attempts (email, ip, success, created_at) VALUES (:email, :ip, :success, :created)',
            ['email' => $email, 'ip' => $ip, 'success' => $ok ? 1 : 0, 'created' => app_now()],
        );
        if (!$ok) {
            throw new HttpError(401, 'Неверный email или пароль', 'unauthorized');
        }
        $db->exec('UPDATE users SET last_login_at = :at WHERE id = :id', ['at' => app_now(), 'id' => $user['id']]);
        return self::actorFromUser($db, $user);
    }

    public static function issueSession(Db $db, int $userId, string $ip): array
    {
        $token = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(16));
        $db->insert(
            'INSERT INTO sessions (user_id, token_hash, csrf_token, expires_at, ip, created_at)
             VALUES (:user_id, :hash, :csrf, :expires, :ip, :created)',
            [
                'user_id' => $userId,
                'hash' => hash('sha256', $token),
                'csrf' => $csrf,
                'expires' => (new DateTimeImmutable('+14 days'))->format('Y-m-d H:i:s'),
                'ip' => $ip,
                'created' => app_now(),
            ],
        );
        return ['token' => $token, 'csrf' => $csrf];
    }

    public static function userByToken(Db $db, string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $session = $db->one(
            'SELECT * FROM sessions WHERE token_hash = :hash AND revoked_at IS NULL AND expires_at >= :now',
            ['hash' => hash('sha256', $token), 'now' => app_now()],
        );
        if (!$session) {
            return null;
        }
        $user = $db->one('SELECT * FROM users WHERE id = :id AND status = :status', ['id' => $session['user_id'], 'status' => 'active']);
        if (!$user) {
            return null;
        }
        $actor = self::actorFromUser($db, $user);
        $actor['csrf'] = $session['csrf_token'];
        $actor['session_id'] = (int) $session['id'];
        return $actor;
    }

    public static function revoke(Db $db, int $sessionId): void
    {
        $db->exec('UPDATE sessions SET revoked_at = :at WHERE id = :id', ['at' => app_now(), 'id' => $sessionId]);
    }

    public static function requestReset(Db $db, string $email, bool $debug): array
    {
        $email = mb_strtolower(trim($email));
        $user = $db->one('SELECT id FROM users WHERE email = :email AND status = :status', ['email' => $email, 'status' => 'active']);
        $payload = ['message' => 'Если такой email есть в системе, для него создана ссылка сброса. Почта на сервере не подключена.'];
        if (!$user) {
            return $payload;
        }
        $token = bin2hex(random_bytes(16));
        $db->insert(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (:user, :hash, :expires, :created)',
            [
                'user' => $user['id'],
                'hash' => hash('sha256', $token),
                'expires' => (new DateTimeImmutable('+2 hours'))->format('Y-m-d H:i:s'),
                'created' => app_now(),
            ],
        );
        if ($debug) {
            $payload['reset_token'] = $token;
        }
        return $payload;
    }

    public static function resetPassword(Db $db, string $token, string $password): void
    {
        if (mb_strlen($password) < 8) {
            throw new HttpError(422, 'Пароль должен быть не короче 8 символов');
        }
        $row = $db->one(
            'SELECT * FROM password_resets WHERE token_hash = :hash AND used_at IS NULL AND expires_at >= :now',
            ['hash' => hash('sha256', $token), 'now' => app_now()],
        );
        if (!$row) {
            throw new HttpError(422, 'Ссылка сброса недействительна');
        }
        $db->exec('UPDATE users SET password_hash = :hash WHERE id = :id', [
            'hash' => password_hash($password, PASSWORD_DEFAULT),
            'id' => $row['user_id'],
        ]);
        $db->exec('UPDATE password_resets SET used_at = :at WHERE id = :id', ['at' => app_now(), 'id' => $row['id']]);
        $db->exec('UPDATE sessions SET revoked_at = :at WHERE user_id = :id AND revoked_at IS NULL', ['at' => app_now(), 'id' => $row['user_id']]);
        audit_change($db, (int) $row['user_id'], 'users', (int) $row['user_id'], 'password_reset', 'password', null, 'updated');
    }
}
