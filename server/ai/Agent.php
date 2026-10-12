<?php
declare(strict_types=1);

final class Agent
{
    public function __construct(private AIProvider $provider, private Db $db, private array $config)
    {
    }

    public static function make(Db $db, array $config): self
    {
        $tools = new ToolRegistry();
        return new self(new GrokProvider($config, $tools), $db, $config);
    }

    public function chat(array $actor, array $input): array
    {
        actor_require($actor, 'ai.use');
        $message = trim((string) ($input['message'] ?? ''));
        if ($message === '') {
            throw new HttpError(422, 'Введите сообщение');
        }
        $conversationId = (int) ($input['conversation_id'] ?? 0);
        if ($conversationId) {
            $own = $this->db->one('SELECT * FROM ai_conversations WHERE id = :id AND user_id = :user', ['id' => $conversationId, 'user' => $actor['id']]);
            if (!$own) {
                throw new HttpError(404, 'Диалог не найден');
            }
        } else {
            $conversationId = $this->db->insert(
                'INSERT INTO ai_conversations (user_id, title, created_at, updated_at) VALUES (:user, :title, :created, :updated)',
                ['user' => $actor['id'], 'title' => mb_substr($message, 0, 80), 'created' => app_now(), 'updated' => app_now()],
            );
        }
        $this->storeMessage($conversationId, 'user', $message, null, []);
        $slash = $this->slash($actor, $message);
        $key = $this->config['grok_api_key'] ?? '';
        if ($key === '') {
            if ($slash) {
                return $this->finish($conversationId, $slash['text'], $slash['sources'], $slash['links'], true);
            }
            throw new HttpError(503, 'AI Agent временно недоступен.', 'ai_unavailable');
        }
        $tools = (new ToolRegistry())->definitions($actor);
        $messages = $this->messages($actor, $conversationId, $input['context'] ?? []);
        $links = [];
        $sources = [];
        $failed = false;
        for ($i = 0; $i < 4; $i++) {
            $reply = $this->provider->chat($messages, $tools);
            $calls = $reply['tool_calls'] ?? [];
            if (!$calls) {
                $text = trim((string) ($reply['content'] ?? ''));
                if ($text === '') {
                    $text = 'Не удалось получить данные из CRM.';
                }
                if ($failed && $text !== '') {
                    $text = "Не удалось получить данные из CRM.\n\n" . $text;
                }
                return $this->finish($conversationId, $text, $this->sourcesText($sources), $links, false);
            }
            $messages[] = $reply;
            foreach ($calls as $call) {
                $name = $call['function']['name'] ?? '';
                $args = json_decode($call['function']['arguments'] ?? '{}', true);
                if (!is_array($args)) {
                    $args = [];
                }
                $result = $this->provider->toolCall($name, $args, $actor, $this->db);
                if (!empty($result['error'])) {
                    $failed = true;
                }
                $sources[] = $name;
                $links = array_merge($links, $this->linksFrom($result));
                $encoded = json_encode($result, JSON_UNESCAPED_UNICODE);
                $this->db->insert(
                    'INSERT INTO ai_tool_calls (conversation_id, tool_name, arguments_text, result_text, created_at)
                     VALUES (:conversation, :tool, :args, :result, :created)',
                    [
                        'conversation' => $conversationId,
                        'tool' => $name,
                        'args' => mb_substr(json_encode($args, JSON_UNESCAPED_UNICODE), 0, 2000),
                        'result' => mb_substr((string) $encoded, 0, 4000),
                        'created' => app_now(),
                    ],
                );
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'] ?? $name,
                    'content' => (string) $encoded,
                ];
            }
        }
        return $this->finish($conversationId, 'Не удалось получить данные из CRM.', $this->sourcesText($sources), $links, false);
    }

    public function listConversations(array $actor): array
    {
        actor_require($actor, 'ai.use');
        return $this->db->all('SELECT id, title, updated_at FROM ai_conversations WHERE user_id = :user ORDER BY updated_at DESC LIMIT 30', ['user' => $actor['id']]);
    }

    public function conversation(array $actor, int $id): array
    {
        actor_require($actor, 'ai.use');
        $row = $this->db->one('SELECT * FROM ai_conversations WHERE id = :id AND user_id = :user', ['id' => $id, 'user' => $actor['id']]);
        if (!$row) {
            throw new HttpError(404, 'Диалог не найден');
        }
        $messages = $this->db->all('SELECT * FROM ai_messages WHERE conversation_id = :id ORDER BY id', ['id' => $id]);
        foreach ($messages as &$message) {
            $message['links'] = $this->db->all('SELECT entity_type, entity_id, label FROM ai_message_links WHERE message_id = :id', ['id' => $message['id']]);
        }
        return ['conversation' => $row, 'messages' => $messages];
    }

    public function delete(array $actor, int $id): void
    {
        actor_require($actor, 'ai.use');
        $this->db->exec('DELETE FROM ai_conversations WHERE id = :id AND user_id = :user', ['id' => $id, 'user' => $actor['id']]);
    }

    private function slash(array $actor, string $message): ?array
    {
        if (!str_starts_with($message, '/')) {
            return null;
        }
        $cmd = strtolower(strtok($message, ' ') ?: '');
        $map = [
            '/sales' => 'get_sales_funnel',
            '/revenue' => 'get_revenue',
            '/customers' => 'get_customers',
            '/renewals' => 'get_expiring_subscriptions',
            '/deliveries' => 'get_delivery_schedule',
            '/inventory' => 'get_low_stock_products',
            '/tasks' => 'get_overdue_tasks',
            '/managers' => 'get_sales_by_manager',
            '/analytics' => 'get_analytics',
        ];
        if (!isset($map[$cmd])) {
            return null;
        }
        $tool = $map[$cmd];
        $args = $cmd === '/renewals' ? ['days' => 3] : ($cmd === '/revenue' || $cmd === '/analytics' ? ['period' => 'month'] : []);
        $result = (new ToolRegistry())->call($tool, $args, $actor, $this->db);
        if (!empty($result['error'])) {
            return ['text' => 'Не удалось получить данные из CRM.', 'sources' => $tool, 'links' => []];
        }
        $lines = ["Данные CRM по команде {$cmd}."];
        foreach ($this->flatten($result) as $line) {
            $lines[] = $line;
        }
        return ['text' => implode("\n", $lines), 'sources' => $tool . ' · ' . app_today(), 'links' => $this->linksFrom($result)];
    }

    private function flatten(array $data, string $prefix = ''): array
    {
        $lines = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (array_is_list($value)) {
                    $lines[] = $prefix . $key . ': ' . count($value);
                    foreach (array_slice($value, 0, 8) as $item) {
                        if (is_array($item)) {
                            $label = $item['name'] ?? $item['full_name'] ?? $item['title'] ?? $item['label'] ?? json_encode($item, JSON_UNESCAPED_UNICODE);
                            $lines[] = '• ' . $label;
                        }
                    }
                } else {
                    foreach ($this->flatten($value, $prefix . $key . ' ') as $line) {
                        $lines[] = $line;
                    }
                }
            } else {
                $lines[] = trim($prefix . $key) . ': ' . (string) $value;
            }
        }
        return array_slice($lines, 0, 40);
    }

    private function messages(array $actor, int $conversationId, array $context): array
    {
        $prompt = trim((string) (setting($this->db, 'ai_system_prompt', '') ?? ''));
        if ($prompt === '') {
            $prompt = (string) file_get_contents(__DIR__ . '/system_prompt.txt');
        }
        $rules = (string) file_get_contents(__DIR__ . '/business_rules.txt');
        $page = trim((string) ($context['path'] ?? ''));
        $extra = "Пользователь: {$actor['full_name']}, роль {$actor['role_name']}. Страница: {$page}.";
        if (!empty($context['customer_id']) && actor_can($actor, 'customers.view')) {
            $card = (new ToolRegistry())->call('get_customer', ['customer_id' => (int) $context['customer_id']], $actor, $this->db);
            if (empty($card['error'])) {
                $extra .= ' Открыт клиент: ' . json_encode($card, JSON_UNESCAPED_UNICODE);
            }
        }
        $history = $this->db->all(
            'SELECT role, content FROM ai_messages WHERE conversation_id = :id AND role IN (\'user\', \'assistant\') ORDER BY id DESC LIMIT 12',
            ['id' => $conversationId],
        );
        $messages = [['role' => 'system', 'content' => $prompt . "\n\n" . $rules . "\n\n" . $extra]];
        foreach (array_reverse($history) as $row) {
            $messages[] = ['role' => $row['role'], 'content' => $row['content']];
        }
        return $messages;
    }

    private function finish(int $conversationId, string $text, ?string $sources, array $links, bool $direct): array
    {
        if ($direct) {
            $text = "AI Agent временно недоступен.\n\nПрямой ответ из CRM, без анализа Grok:\n" . $text;
        }
        $messageId = $this->storeMessage($conversationId, 'assistant', $text, $sources, $links);
        $this->db->exec('UPDATE ai_conversations SET updated_at = :at WHERE id = :id', ['at' => app_now(), 'id' => $conversationId]);
        return [
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'message' => $text,
            'sources' => $sources,
            'links' => $links,
            'unavailable' => $direct,
        ];
    }

    private function storeMessage(int $conversationId, string $role, string $content, ?string $sources, array $links): int
    {
        $id = $this->db->insert(
            'INSERT INTO ai_messages (conversation_id, role, content, sources_text, created_at)
             VALUES (:conversation, :role, :content, :sources, :created)',
            ['conversation' => $conversationId, 'role' => $role, 'content' => $content, 'sources' => $sources, 'created' => app_now()],
        );
        foreach ($links as $link) {
            $this->db->insert(
                'INSERT INTO ai_message_links (message_id, entity_type, entity_id, label) VALUES (:message, :type, :id, :label)',
                ['message' => $id, 'type' => $link['type'], 'id' => $link['id'], 'label' => $link['label']],
            );
        }
        return $id;
    }

    private function linksFrom(array $result): array
    {
        $links = [];
        $rows = $result['customers'] ?? $result['data'] ?? [];
        if (isset($result['id'], $result['name'])) {
            $rows[] = $result;
        }
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['id'])) {
                continue;
            }
            $links[] = [
                'type' => 'customer',
                'id' => (int) $row['id'],
                'label' => (string) ($row['name'] ?? $row['full_name'] ?? ('Клиент ' . $row['id'])),
            ];
        }
        return array_slice($links, 0, 12);
    }

    private function sourcesText(array $tools): string
    {
        $tools = array_values(array_unique($tools));
        return $tools ? ('Инструменты: ' . implode(', ', $tools) . '. Дата: ' . app_today()) : '';
    }
}
