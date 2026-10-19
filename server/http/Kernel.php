<?php
declare(strict_types=1);

final class Kernel
{
    public static function handle(Db $db, array $config): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = preg_replace('#^/api#', '', $path) ?: '/';
        $path = rtrim($path, '/') ?: '/';
        $raw = file_get_contents('php://input') ?: '';
        $body = [];
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_contains($contentType, 'application/json') && $raw !== '') {
            $decoded = json_decode($raw, true);
            $body = is_array($decoded) ? $decoded : [];
        } elseif ($_POST) {
            $body = $_POST;
        }
        $query = $_GET;
        try {
            if ($method === 'GET' && $path === '/health') {
                self::send(['ok' => true]);
                return;
            }
            $public = self::publicRoute($db, $config, $method, $path, $body, $raw);
            if ($public !== null) {
                self::send($public);
                return;
            }
            $token = $_COOKIE['fff_session'] ?? '';
            $actor = AuthService::userByToken($db, $token);
            if (!$actor) {
                throw new HttpError(401, 'Нужно войти', 'unauthorized');
            }
            if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
                $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
                if (!hash_equals((string) $actor['csrf'], $csrf)) {
                    throw new HttpError(419, 'Сессия устарела, обновите страницу', 'csrf');
                }
            }
            AutomationService::ensureToday($db, $config);
            self::send(self::route($db, $config, $actor, $method, $path, $body, $query));
        } catch (HttpError $e) {
            http_response_code($e->status);
            echo json_encode(['error' => $e->codeName, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(500);
            $message = !empty($config['debug']) ? $e->getMessage() : 'Внутренняя ошибка';
            file_put_contents(dirname(__DIR__) . '/data/error.log', date('c') . ' ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
            echo json_encode(['error' => 'server', 'message' => $message], JSON_UNESCAPED_UNICODE);
        }
    }

    private static function publicRoute(Db $db, array $config, string $method, string $path, array $body, string $raw): ?array
    {
        if ($method === 'POST' && $path === '/auth/login') {
            $actor = AuthService::attempt($db, (string) ($body['email'] ?? ''), (string) ($body['password'] ?? ''), $_SERVER['REMOTE_ADDR'] ?? '');
            $session = AuthService::issueSession($db, $actor['id'], $_SERVER['REMOTE_ADDR'] ?? '');
            self::cookie($session['token']);
            AutomationService::ensureToday($db, $config);
            return ['user' => $actor, 'csrf' => $session['csrf']];
        }
        if ($method === 'POST' && $path === '/auth/forgot') {
            return AuthService::requestReset($db, (string) ($body['email'] ?? ''), (bool) $config['debug']);
        }
        if ($method === 'POST' && $path === '/auth/reset') {
            AuthService::resetPassword($db, (string) ($body['token'] ?? ''), (string) ($body['password'] ?? ''));
            return ['ok' => true];
        }
        if ($method === 'GET' && preg_match('#^/public/pay/([a-f0-9]+)$#', $path, $m)) {
            return BillingService::publicStatus($db, $m[1]);
        }
        if ($method === 'POST' && preg_match('#^/webhooks/payments/([a-z]+)$#', $path, $m)) {
            $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
            return BillingService::webhook($db, $config, $m[1], $raw, $signature);
        }
        return null;
    }

    private static function route(Db $db, array $config, array $actor, string $method, string $path, array $body, array $query): array
    {
        if ($method === 'GET' && $path === '/auth/me') {
            return ['user' => $actor, 'csrf' => $actor['csrf']];
        }
        if ($method === 'POST' && $path === '/auth/logout') {
            AuthService::revoke($db, (int) $actor['session_id']);
            self::cookie('', -3600);
            return ['ok' => true];
        }
        if ($method === 'GET' && $path === '/dashboard') return InsightService::dashboard($db, $actor);
        if ($method === 'GET' && $path === '/badges') return InsightService::badges($db, $actor);
        if ($method === 'GET' && $path === '/search') return InsightService::search($db, $actor, (string) ($query['q'] ?? ''));
        if ($method === 'GET' && $path === '/finance') return InsightService::finance($db, $actor, (string) ($query['period'] ?? 'month'));
        if ($method === 'GET' && $path === '/analytics') return InsightService::analytics($db, $actor, (string) ($query['period'] ?? 'month'));
        if ($method === 'GET' && $path === '/audit') return InsightService::audit($db, $actor, clamp_page($query['page'] ?? 1));

        if ($method === 'GET' && $path === '/customers') return CrmService::listCustomers($db, $actor, $query);
        if ($method === 'POST' && $path === '/customers') return CrmService::createCustomer($db, $actor, $body);
        if ($method === 'POST' && $path === '/customers/bulk') return CrmService::bulk($db, $config, $actor, $body);
        if ($method === 'POST' && $path === '/imports/clients/upload') return ClientImportService::upload($db, $actor, $_FILES['file'] ?? []);
        if ($method === 'POST' && $path === '/imports/clients/preview') return ClientImportService::preview($db, $actor, $body);
        if ($method === 'POST' && $path === '/imports/clients/commit') return ClientImportService::commit($db, $actor, $body);
        if ($method === 'GET' && preg_match('#^/imports/clients/(\d+)$#', $path, $m)) return ClientImportService::getBatch($db, $actor, (int) $m[1]);
        if ($method === 'POST' && preg_match('#^/imports/clients/(\d+)/rollback$#', $path, $m)) return ClientImportService::rollback($db, $actor, (int) $m[1]);
        if ($method === 'POST' && preg_match('#^/customers/(\d+)/rations$#', $path, $m)) return NutritionService::create($db, $actor, (int) $m[1], $body);
        if ($method === 'PATCH' && preg_match('#^/customers/(\d+)/rations/(\d+)$#', $path, $m)) {
            return NutritionService::update($db, $actor, (int) $m[1], (int) $m[2], $body);
        }
        if ($method === 'GET' && preg_match('#^/customers/(\d+)$#', $path, $m)) return CrmService::getCustomer($db, $actor, (int) $m[1]);
        if ($method === 'PATCH' && preg_match('#^/customers/(\d+)$#', $path, $m)) return CrmService::updateCustomer($db, $actor, (int) $m[1], $body);
        if ($method === 'POST' && preg_match('#^/customers/(\d+)/discount$#', $path, $m)) return CrmService::discount($db, $actor, (int) $m[1], $body);
        if ($method === 'POST' && preg_match('#^/customers/(\d+)/documents$#', $path, $m)) return self::upload($db, $actor, (int) $m[1]);
        if ($method === 'GET' && preg_match('#^/documents/(\d+)$#', $path, $m)) return self::download($db, $actor, (int) $m[1]);

        if ($method === 'GET' && $path === '/leads') return CrmService::listLeads($db, $actor, $query);
        if ($method === 'POST' && $path === '/leads') return CrmService::saveLead($db, $actor, null, $body);
        if ($method === 'PATCH' && preg_match('#^/leads/(\d+)$#', $path, $m)) return CrmService::saveLead($db, $actor, (int) $m[1], $body);

        if ($method === 'GET' && $path === '/subscriptions') return CrmService::listSubscriptions($db, $actor, $query);
        if ($method === 'POST' && preg_match('#^/subscriptions/(\d+)/(pause|resume|cancel)$#', $path, $m)) {
            $state = $m[2] === 'resume' ? 'active' : ($m[2] === 'pause' ? 'paused' : 'cancelled');
            return CrmService::setSubscriptionState($db, $actor, (int) $m[1], $state);
        }

        if ($method === 'GET' && $path === '/payments') return BillingService::listPayments($db, $actor, $query);
        if ($method === 'POST' && $path === '/payments') return BillingService::create($db, $config, $actor, $body);
        if ($method === 'POST' && preg_match('#^/payments/(\d+)/mark-paid$#', $path, $m)) return BillingService::markPaid($db, $config, $actor, (int) $m[1]);
        if ($method === 'POST' && preg_match('#^/payments/(\d+)/cancel$#', $path, $m)) return BillingService::cancel($db, $config, $actor, (int) $m[1]);
        if ($method === 'POST' && preg_match('#^/payments/(\d+)/refund$#', $path, $m)) return BillingService::refund($db, $config, $actor, (int) $m[1]);
        if ($method === 'GET' && $path === '/orders') return BillingService::listOrders($db, $actor, $query);

        if ($method === 'GET' && $path === '/deliveries') return OpsService::deliveries($db, $actor, $query);
        if ($method === 'GET' && $path === '/deliveries/calendar') return OpsService::calendar($db, $actor, (string) ($query['month'] ?? date('Y-m')));
        if ($method === 'POST' && $path === '/deliveries') return OpsService::saveDelivery($db, $actor, null, $body);
        if ($method === 'PATCH' && preg_match('#^/deliveries/(\d+)$#', $path, $m)) return OpsService::saveDelivery($db, $actor, (int) $m[1], $body);

        if ($method === 'GET' && $path === '/programs') return OpsService::programs($db, $actor);
        if ($method === 'POST' && $path === '/programs') return OpsService::saveProgram($db, $actor, null, $body);
        if ($method === 'PATCH' && preg_match('#^/programs/(\d+)$#', $path, $m)) return OpsService::saveProgram($db, $actor, (int) $m[1], $body);
        if ($method === 'GET' && $path === '/inventory') return OpsService::inventory($db, $actor, $query);
        if ($method === 'POST' && $path === '/products') return OpsService::saveProduct($db, $actor, $body);
        if ($method === 'POST' && $path === '/inventory/move') return OpsService::moveStock($db, $config, $actor, $body);

        if ($method === 'GET' && $path === '/staff') return self::staff($db, $actor);
        if ($method === 'GET' && $path === '/employees') return PeopleService::employees($db, $actor);
        if ($method === 'POST' && $path === '/employees') return PeopleService::saveEmployee($db, $actor, null, $body);
        if ($method === 'PATCH' && preg_match('#^/employees/(\d+)$#', $path, $m)) return PeopleService::saveEmployee($db, $actor, (int) $m[1], $body);
        if ($method === 'GET' && $path === '/tasks') return PeopleService::tasks($db, $actor, $query);
        if ($method === 'POST' && $path === '/tasks') return PeopleService::saveTask($db, $config, $actor, null, $body);
        if ($method === 'PATCH' && preg_match('#^/tasks/(\d+)$#', $path, $m)) return PeopleService::saveTask($db, $config, $actor, (int) $m[1], $body);

        if ($method === 'GET' && $path === '/notifications') return ['data' => CommService::listNotifications($db, $actor)];
        if ($method === 'POST' && $path === '/notifications/read') {
            CommService::markRead($db, $actor, $body);
            return ['ok' => true];
        }
        if ($method === 'GET' && $path === '/push/config') {
            return ['enabled' => ($config['vapid_public'] ?? '') !== '', 'publicKey' => $config['vapid_public'] ?? ''];
        }
        if ($method === 'POST' && $path === '/push/subscribe') {
            CommService::savePush($db, $actor, $body);
            return ['ok' => true];
        }

        if ($method === 'GET' && $path === '/whatsapp/conversations') return WhatsAppService::conversations($db, $actor, (string) ($query['q'] ?? ''));
        if ($method === 'POST' && $path === '/whatsapp/conversations') return WhatsAppService::openForCustomer($db, $actor, (int) ($body['customer_id'] ?? 0));
        if ($method === 'GET' && preg_match('#^/whatsapp/conversations/(\d+)$#', $path, $m)) {
            $payload = WhatsAppService::messages($db, $actor, (int) $m[1]);
            $payload['channel_ready'] = CommService::channelReady($db, $config);
            return $payload;
        }
        if ($method === 'POST' && preg_match('#^/whatsapp/conversations/(\d+)/messages$#', $path, $m)) return WhatsAppService::send($db, $config, $actor, (int) $m[1], (string) ($body['body'] ?? ''));
        if ($method === 'POST' && preg_match('#^/whatsapp/conversations/(\d+)/link$#', $path, $m)) return WhatsAppService::link($db, $actor, (int) $m[1], (int) ($body['customer_id'] ?? 0));
        if ($method === 'POST' && preg_match('#^/whatsapp/conversations/(\d+)/convert$#', $path, $m)) return WhatsAppService::convert($db, $actor, (int) $m[1]);
        if ($method === 'POST' && preg_match('#^/whatsapp/conversations/(\d+)/assign$#', $path, $m)) {
            WhatsAppService::assign($db, $actor, (int) $m[1], (int) ($body['manager_id'] ?? 0));
            return ['ok' => true];
        }
        if ($method === 'POST' && preg_match('#^/whatsapp/conversations/(\d+)/task$#', $path, $m)) return WhatsAppService::taskFromMessage($db, $config, $actor, (int) $m[1], $body);

        if ($method === 'GET' && $path === '/templates') {
            actor_require($actor, 'settings.manage');
            return ['data' => $db->all('SELECT * FROM message_templates ORDER BY id')];
        }
        if ($method === 'PUT' && preg_match('#^/templates/(\d+)$#', $path, $m)) {
            actor_require($actor, 'settings.manage');
            $db->exec('UPDATE message_templates SET name = :name, body = :body, updated_at = :at WHERE id = :id', [
                'name' => (string) ($body['name'] ?? ''),
                'body' => (string) ($body['body'] ?? ''),
                'at' => app_now(),
                'id' => (int) $m[1],
            ]);
            return $db->one('SELECT * FROM message_templates WHERE id = :id', ['id' => $m[1]]);
        }
        if ($method === 'GET' && $path === '/automations') {
            actor_require($actor, 'settings.manage');
            $rules = $db->all('SELECT * FROM automation_rules ORDER BY id');
            foreach ($rules as &$rule) {
                $rule['conditions'] = $db->all('SELECT * FROM automation_conditions WHERE rule_id = :id', ['id' => $rule['id']]);
                $rule['actions'] = $db->all('SELECT * FROM automation_actions WHERE rule_id = :id', ['id' => $rule['id']]);
            }
            return ['data' => $rules];
        }
        if ($method === 'POST' && $path === '/automations') return self::createRule($db, $actor, $body);
        if ($method === 'PATCH' && preg_match('#^/automations/(\d+)$#', $path, $m)) {
            actor_require($actor, 'settings.manage');
            $db->exec('UPDATE automation_rules SET enabled = :enabled WHERE id = :id', ['enabled' => !empty($body['enabled']) ? 1 : 0, 'id' => (int) $m[1]]);
            return ['ok' => true];
        }
        if ($method === 'GET' && $path === '/settings') return self::settings($db, $config, $actor);
        if ($method === 'PUT' && $path === '/settings') return self::saveSettings($db, $actor, $body);

        if ($method === 'POST' && $path === '/ai/chat') return Agent::make($db, $config)->chat($actor, $body);
        if ($method === 'GET' && $path === '/ai/conversations') return ['data' => Agent::make($db, $config)->listConversations($actor)];
        if ($method === 'GET' && preg_match('#^/ai/conversations/(\d+)$#', $path, $m)) return Agent::make($db, $config)->conversation($actor, (int) $m[1]);
        if ($method === 'DELETE' && preg_match('#^/ai/conversations/(\d+)$#', $path, $m)) {
            Agent::make($db, $config)->delete($actor, (int) $m[1]);
            return ['ok' => true];
        }
        throw new HttpError(404, 'Маршрут не найден');
    }

    private static function staff(Db $db, array $actor): array
    {
        if (!actor_can($actor, 'customers.manage') && !actor_can($actor, 'leads.manage') && !actor_can($actor, 'employees.view') && !actor_can($actor, 'deliveries.manage')) {
            throw new HttpError(403, 'Недостаточно прав', 'forbidden');
        }
        return ['data' => $db->all('SELECT id, full_name, position FROM employees WHERE status = :status ORDER BY full_name', ['status' => 'active'])];
    }

    private static function settings(Db $db, array $config, array $actor): array
    {
        $data = [
            'company_name' => setting($db, 'company_name', 'FOODFORFIT'),
            'whatsapp_enabled' => setting($db, 'whatsapp_enabled', '0') === '1',
            'whatsapp_ready' => CommService::channelReady($db, $config),
            'payment_provider' => $config['payment_provider'] ?? 'manual',
            'kaspi_ready' => ($config['kaspi_api_key'] ?? '') !== '',
            'ai_mode' => setting($db, 'ai_mode', 'read_only'),
            'push_ready' => ($config['vapid_public'] ?? '') !== '',
            'grok_ready' => ($config['grok_api_key'] ?? '') !== '',
        ];
        if (actor_can($actor, 'system.manage')) {
            $data['ai_system_prompt'] = setting($db, 'ai_system_prompt', '') ?: (string) file_get_contents(dirname(__DIR__) . '/ai/system_prompt.txt');
        }
        return $data;
    }

    private static function saveSettings(Db $db, array $actor, array $body): array
    {
        actor_require($actor, 'settings.manage');
        if (array_key_exists('whatsapp_enabled', $body)) {
            set_setting($db, 'whatsapp_enabled', !empty($body['whatsapp_enabled']) ? '1' : '0');
        }
        if (array_key_exists('company_name', $body)) {
            set_setting($db, 'company_name', trim((string) $body['company_name']));
        }
        if (array_key_exists('ai_system_prompt', $body)) {
            actor_require($actor, 'system.manage');
            set_setting($db, 'ai_system_prompt', (string) $body['ai_system_prompt']);
        }
        audit_change($db, $actor['id'], 'settings', null, 'update', 'settings', null, 'updated');
        return ['ok' => true];
    }

    private static function createRule(Db $db, array $actor, array $body): array
    {
        actor_require($actor, 'settings.manage');
        $trigger = (string) ($body['trigger_code'] ?? '');
        if (!in_array($trigger, ['subscription_days', 'payment_received', 'stock_below_min'], true)) {
            throw new HttpError(422, 'Неизвестный триггер');
        }
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            throw new HttpError(422, 'Укажите название правила');
        }
        $id = $db->insert(
            'INSERT INTO automation_rules (name, trigger_code, enabled, created_at) VALUES (:name, :trigger, 1, :created)',
            ['name' => $name, 'trigger' => $trigger, 'created' => app_now()],
        );
        if ($trigger === 'subscription_days') {
            $db->insert('INSERT INTO automation_conditions (rule_id, field, op, value) VALUES (:rule, :field, :op, :value)', [
                'rule' => $id, 'field' => 'days', 'op' => 'eq', 'value' => (string) ($body['days'] ?? '3'),
            ]);
        }
        foreach ($body['actions'] ?? [] as $action) {
            $code = (string) ($action['code'] ?? '');
            if (!in_array($code, ['notify', 'task', 'draft_message', 'recorded'], true)) {
                continue;
            }
            $db->insert('INSERT INTO automation_actions (rule_id, action_code, param) VALUES (:rule, :code, :param)', [
                'rule' => $id, 'code' => $code, 'param' => $action['param'] ?? null,
            ]);
        }
        return ['id' => $id];
    }

    private static function upload(Db $db, array $actor, int $customerId): array
    {
        actor_require($actor, 'customers.manage');
        BillingService::customer($db, $actor, $customerId);
        if (empty($_FILES['file']['tmp_name'])) {
            throw new HttpError(422, 'Выберите файл');
        }
        $file = $_FILES['file'];
        if (($file['size'] ?? 0) > 8_000_000) {
            throw new HttpError(422, 'Файл больше 8 МБ');
        }
        $ext = pathinfo((string) $file['name'], PATHINFO_EXTENSION);
        $ext = preg_replace('/[^a-zA-Z0-9]/', '', (string) $ext);
        $stored = bin2hex(random_bytes(12)) . ($ext ? '.' . $ext : '');
        $dir = dirname(__DIR__) . '/data/uploads';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $stored)) {
            throw new HttpError(500, 'Не удалось сохранить файл');
        }
        $id = $db->insert(
            'INSERT INTO customer_documents (customer_id, name, filename, mime, size, created_by, created_at)
             VALUES (:customer, :name, :filename, :mime, :size, :user, :created)',
            [
                'customer' => $customerId,
                'name' => (string) $file['name'],
                'filename' => $stored,
                'mime' => (string) ($file['type'] ?: 'application/octet-stream'),
                'size' => (int) $file['size'],
                'user' => $actor['id'],
                'created' => app_now(),
            ],
        );
        return ['id' => $id, 'name' => $file['name']];
    }

    private static function download(Db $db, array $actor, int $id): array
    {
        actor_require($actor, 'customers.view');
        $doc = $db->one('SELECT * FROM customer_documents WHERE id = :id', ['id' => $id]);
        if (!$doc) {
            throw new HttpError(404, 'Документ не найден');
        }
        BillingService::customer($db, $actor, (int) $doc['customer_id']);
        $path = dirname(__DIR__) . '/data/uploads/' . basename($doc['filename']);
        if (!is_file($path)) {
            throw new HttpError(404, 'Файл не найден');
        }
        header('Content-Type: ' . $doc['mime']);
        header('Content-Disposition: attachment; filename="' . rawurlencode($doc['name']) . '"');
        readfile($path);
        exit;
    }

    private static function cookie(string $value, int $ttl = 1209600): void
    {
        setcookie('fff_session', $value, [
            'expires' => time() + $ttl,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
    }

    private static function send(array $data): void
    {
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
