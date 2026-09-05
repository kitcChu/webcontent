<?php

namespace Kit\WebContent\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin OpenAI-compatible chat client (works with DeepSeek, OpenAI, any
 * compatible gateway). Always asked for JSON responses; decoding is tolerant
 * of markdown fences.
 */
class AiClient
{
    public function chatJson(array $messages, ?float $temperature = null): array
    {
        $response = Http::withToken((string) config('webcontent.ai.api_key'))
            ->timeout((int) config('webcontent.ai.timeout', 120))
            ->post($this->endpoint(), [
                'model' => config('webcontent.ai.model', 'deepseek-chat'),
                'messages' => $messages,
                'temperature' => $temperature ?? config('webcontent.ai.temperature', 0.2),
                'response_format' => ['type' => 'json_object'],
            ]);

        if ($response->failed()) {
            throw new RuntimeException(
                'AI request failed ['.$response->status().']: '.mb_substr($response->body(), 0, 500)
            );
        }

        return $this->decode((string) $response->json('choices.0.message.content', ''));
    }

    protected function endpoint(): string
    {
        return rtrim((string) config('webcontent.ai.base_url', 'https://api.deepseek.com'), '/').'/chat/completions';
    }

    protected function decode(string $content): array
    {
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $content) ?? $content;

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('AI returned invalid JSON: '.mb_substr($content, 0, 300));
        }

        return $decoded;
    }
}
