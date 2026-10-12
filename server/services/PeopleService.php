<?php
declare(strict_types=1);

final class PeopleService
{
    public static function employees(Db $db, array $actor): array
    {
        if (!actor_can($actor, 'employees.view') && !actor_can($actor, 'tasks.view')) {
            throw new HttpError(403, 'Недостаточно прав', 'forbidden');
        }
        $rows = $db->all(
            'SELECT e.*, r.name AS role_name, r.code AS role_code, m.full_name AS manager_name
             FROM employees e
             JOIN roles r ON r.id = e.role_id
             LEFT JOIN employees m ON m.id = e.manager_id
             ORDER BY e.full_name',
        );
        $showSalary = actor_can($actor, 'employees.manage') || actor_can($actor, 'finance.view');
        if (!$showSalary) {
            foreach ($rows as &$row) {
                unset($row['salary']);
            }
        }
        return ['data' => $rows, 'roles' => $db->all('SELECT id, code, name FROM roles ORDER BY id')];
    }

    public static function saveEmployee(Db $db, array $actor, ?int $id, array $input): array
    {
        actor_require($actor, 'employees.manage');
        $name = trim((string) ($input['full_name'] ?? ''));
        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpError(422, 'Укажите имя и корректный email');
        }
        $digits = phone_digits((string) ($input['phone'] ?? ''));
        if (strlen($digits) !== 11) {
            throw new HttpError(422, 'Телефон должен быть в формате +7');
        }
        $role = $db->one('SELECT * FROM roles WHERE id = :id', ['id' => (int) ($input['role_id'] ?? 0)]);
        if (!$role) {
            throw new HttpError(422, 'Выберите роль');
        }
        $payload = [
            'name' => $name,
            'phone' => phone_format($digits),
            'digits' => $digits,
            'email' => $email,
            'position' => (string) ($input['position'] ?? $role['name']),
            'role' => $role['id'],
            'department' => (string) ($input['department'] ?? ''),
            'hired' => $input['hired_at'] ?? app_today(),
            'status' => (string) ($input['status'] ?? 'active'),
            'salary' => money_int($input['salary'] ?? 0),
            'manager' => !empty($input['manager_id']) ? (int) $input['manager_id'] : null,
        ];
        if ($id) {
            $before = $db->one('SELECT * FROM employees WHERE id = :id', ['id' => $id]);
            if (!$before) {
                throw new HttpError(404, 'Сотрудник не найден');
            }
            $payload['id'] = $id;
            $db->exec(
                'UPDATE employees SET full_name=:name, phone=:phone, phone_digits=:digits, email=:email, position=:position, role_id=:role, department=:department, hired_at=:hired, status=:status, salary=:salary, manager_id=:manager WHERE id=:id',
                $payload,
            );
            if ($before['user_id']) {
                $db->exec('UPDATE users SET email = :email, role_id = :role, status = :status WHERE id = :id', [
                    'email' => $email,
                    'role' => $role['id'],
                    'status' => $payload['status'] === 'active' ? 'active' : 'disabled',
                    'id' => $before['user_id'],
                ]);
            }
            audit_fields($db, $actor['id'], 'employees', $id, $before, [
                'full_name' => $name,
                'salary' => $payload['salary'],
                'status' => $payload['status'],
            ]);
        } else {
            $password = (string) ($input['password'] ?? '');
            if (mb_strlen($password) < 8) {
                throw new HttpError(422, 'Задайте пароль не короче 8 символов');
            }
            $id = $db->insert(
                'INSERT INTO employees (full_name, phone, phone_digits, email, position, role_id, department, hired_at, status, salary, manager_id, created_at)
                 VALUES (:name, :phone, :digits, :email, :position, :role, :department, :hired, :status, :salary, :manager, :created)',
                $payload + ['created' => app_now()],
            );
            $userId = $db->insert(
                'INSERT INTO users (employee_id, email, phone, password_hash, role_id, status, created_at)
                 VALUES (:employee, :email, :phone, :hash, :role, :status, :created)',
                [
                    'employee' => $id,
                    'email' => $email,
                    'phone' => $payload['phone'],
                    'hash' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => $role['id'],
                    'status' => 'active',
                    'created' => app_now(),
                ],
            );
            $db->exec('UPDATE employees SET user_id = :user WHERE id = :id', ['user' => $userId, 'id' => $id]);
            audit_change($db, $actor['id'], 'employees', $id, 'create', 'full_name', null, $name);
        }
        $row = $db->one('SELECT * FROM employees WHERE id = :id', ['id' => $id]);
        unset($row['salary']);
        $row['salary'] = $payload['salary'];
        return $row;
    }

    public static function tasks(Db $db, array $actor, array $query): array
    {
        actor_require($actor, 'tasks.view');
        $params = [];
        $sql = 'FROM tasks t
            LEFT JOIN employees e ON e.id = t.assignee_id
            LEFT JOIN customers c ON c.id = t.customer_id
            WHERE 1=1';
        $seeAll = actor_can($actor, 'employees.manage') || actor_can($actor, 'customers.view_all');
        if (!$seeAll) {
            $sql .= ' AND t.assignee_id = :assignee';
            $params['assignee'] = $actor['employee_id'];
        }
        $filter = (string) ($query['filter'] ?? 'mine');
        $today = app_today();
        if ($filter === 'overdue') {
            $sql .= " AND t.status != 'done' AND t.due_date < :today";
            $params['today'] = $today;
        } elseif ($filter === 'today') {
            $sql .= ' AND t.due_date = :today AND t.status != :done';
            $params['today'] = $today;
            $params['done'] = 'done';
        } elseif ($filter === 'week') {
            $sql .= " AND t.status != 'done' AND t.due_date >= :today AND t.due_date <= :end";
            $params['today'] = $today;
            $params['end'] = app_date_shift($today, 7);
        } elseif ($filter === 'done') {
            $sql .= " AND t.status = 'done'";
        } elseif ($filter === 'mine') {
            $sql .= ' AND t.assignee_id = :mine AND t.status != :done';
            $params['mine'] = $actor['employee_id'];
            $params['done'] = 'done';
        }
        $rows = $db->all(
            'SELECT t.*, e.full_name AS assignee_name, c.full_name AS customer_name ' . $sql . ' ORDER BY t.due_date IS NULL, t.due_date, t.id DESC',
            $params,
        );
        return ['data' => $rows];
    }

    public static function saveTask(Db $db, array $config, array $actor, ?int $id, array $input): array
    {
        actor_require($actor, 'tasks.manage');
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new HttpError(422, 'Укажите название задачи');
        }
        $priority = (string) ($input['priority'] ?? 'normal');
        if (!in_array($priority, ['low', 'normal', 'high'], true)) {
            $priority = 'normal';
        }
        $status = (string) ($input['status'] ?? 'open');
        if (!in_array($status, ['open', 'done'], true)) {
            $status = 'open';
        }
        $payload = [
            'title' => $title,
            'description' => $input['description'] ?? null,
            'assignee' => !empty($input['assignee_id']) ? (int) $input['assignee_id'] : $actor['employee_id'],
            'customer' => !empty($input['customer_id']) ? (int) $input['customer_id'] : null,
            'due' => $input['due_date'] ?? app_today(),
            'time' => $input['due_time'] ?? null,
            'priority' => $priority,
            'status' => $status,
        ];
        if ($id) {
            $before = $db->one('SELECT * FROM tasks WHERE id = :id', ['id' => $id]);
            if (!$before) {
                throw new HttpError(404, 'Задача не найдена');
            }
            $payload['id'] = $id;
            $db->exec(
                'UPDATE tasks SET title=:title, description=:description, assignee_id=:assignee, customer_id=:customer, due_date=:due, due_time=:time, priority=:priority, status=:status WHERE id=:id',
                $payload,
            );
            if ((int) $before['assignee_id'] !== (int) $payload['assignee']) {
                CommService::notifyEmployee($db, $config, (int) $payload['assignee'], 'important', 'Вам назначена задача', $title, 'task', $id, '/employees?tab=tasks');
            }
        } else {
            $id = $db->insert(
                'INSERT INTO tasks (title, description, assignee_id, customer_id, due_date, due_time, priority, status, created_by, created_at)
                 VALUES (:title, :description, :assignee, :customer, :due, :time, :priority, :status, :by, :created)',
                $payload + ['by' => $actor['id'], 'created' => app_now()],
            );
            CommService::notifyEmployee($db, $config, (int) $payload['assignee'], 'info', 'Новая задача', $title, 'task', $id, '/employees?tab=tasks');
        }
        return $db->one('SELECT * FROM tasks WHERE id = :id', ['id' => $id]);
    }
}
