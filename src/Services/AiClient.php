<?php

namespace Kit\WebContent\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin OpenAI-compatible chat client (works with DeepSeek, OpenAI, any
 * compatible gateway or local server — llama.cpp, LM Studio, vLLM, proxies).
 * Always asked for JSON responses; decoding is tolerant of markdown fences
 * and reasoning models that bury the answer in reasoning_content.
 */
class AiClient
{
    public function chatJson(array $messages, ?float $temperature = null): array
    {
        $body = [
            'model' => config('webcontent.ai.model', 'deepseek-chat'),
            'messages' => $messages,
            'temperature' => $temperature ?? config('webcontent.ai.temperature', 0.2),
        ];

        // OpenAI-style JSON mode. Optional so servers without grammar/JSON
        // support (some llama.cpp builds) still work — decode() is tolerant.
        if (config('webcontent.ai.json_mode', true)) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        // llama.cpp-style switch to skip chain-of-thought. Reasoning models
        // burn tokens (and minutes on slow boxes) before answering; with
        // thinking off, replies land directly in `content`.
        if (config('webcontent.ai.no_thinking', false)) {
            $body['reasoning_budget'] = 0;
        }

        $response = Http::withToken((string) config('webcontent.ai.api_key'))
            ->timeout((int) config('webcontent.ai.timeout', 300))
            ->connectTimeout(10)
            ->post($this->endpoint(), $body);

        if ($response->failed()) {
            throw new RuntimeException(
                'AI request failed ['.$response->status().']: '.mb_substr($response->body(), 0, 500)
            );
        }

        $message = $response->json('choices.0.message', []);
        $content = (string) ($message['content'] ?? '');

        // Reasoning models (Qwen3, DeepSeek-R1, …) sometimes leave `content`
        // empty and put everything — the JSON included — into
        // `reasoning_content`. Fall back to scanning that text.
        if (trim($content) === '' && isset($message['reasoning_content'])) {
            $content = (string) $message['reasoning_content'];
        }

        return $this->decode($content);
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

        if (is_array($decoded)) {
            return $decoded;
        }

        // Chatty replies ("Sure! Here is the JSON: {...} hope that helps")
        // and reasoning dumps: pull out the first balanced JSON object.
        $decoded = $this->extractEmbeddedJson($content);

        if ($decoded === null) {
            throw new RuntimeException('AI returned invalid JSON: '.mb_substr($content, 0, 300));
        }

        return $decoded;
    }

    /**
     * Find the first balanced {...} in free text and decode it.
     */
    protected function extractEmbeddedJson(string $text): ?array
    {
        $start = strpos($text, '{');

        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = mb_strlen($text);

        for ($i = $start; $i < $length; $i++) {
            $char = mb_substr($text, $i, 1);

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    $candidate = mb_substr($text, $start, $i - $start + 1);
                    $decoded = json_decode($candidate, true);

                    return is_array($decoded) ? $decoded : null;
                }
            }
        }

        return null;
    }
}
