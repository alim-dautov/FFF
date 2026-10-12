<?php
declare(strict_types=1);

interface AIProvider
{
    public function chat(array $messages, array $tools): array;

    public function stream(array $messages, array $tools, callable $onDelta): array;

    public function toolCall(string $name, array $args, array $actor, Db $db): array;
}

final class GrokProvider implements AIProvider
{
    public function __construct(private array $config, private ToolRegistry $tools)
    {
    }

    public function chat(array $messages, array $tools): array
    {
        $key = $this->config['grok_api_key'] ?? '';
        if ($key === '') {
            throw new HttpError(503, 'AI Agent временно недоступен.', 'ai_unavailable');
        }
        $payload = [
            'model' => $this->config['grok_model'] ?: 'grok-4',
            'messages' => $messages,
            'temperature' => 0.2,
        ];
        if ($tools) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }
        $ch = curl_init('https://api.x.ai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false || $code >= 400) {
            throw new HttpError(503, 'AI Agent временно недоступен.', 'ai_unavailable');
        }
        $data = json_decode($raw, true);
        $message = $data['choices'][0]['message'] ?? null;
        if (!is_array($message)) {
            throw new HttpError(503, 'AI Agent временно недоступен.', 'ai_unavailable');
        }
        return $message;
    }

    public function stream(array $messages, array $tools, callable $onDelta): array
    {
        $message = $this->chat($messages, $tools);
        if (!empty($message['content'])) {
            $onDelta((string) $message['content']);
        }
        return $message;
    }

    public function toolCall(string $name, array $args, array $actor, Db $db): array
    {
        return $this->tools->call($name, $args, $actor, $db);
    }
}
