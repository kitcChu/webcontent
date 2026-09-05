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

        // Thinking-suppression, server-dependent. Config value:
        //   false/'0'      -> send nothing (default)
        //   true/'1'/'budget'  -> reasoning_budget: 0        (llama.cpp budget switch)
        //   'template'     -> chat_template_kwargs.enable_thinking=false (Qwen3-style)
        //   'both'         -> send both
        // NOTE: strict cloud APIs (OpenAI) reject unknown arguments — only
        // enable this against local/self-hosted servers.
        $mode = strtolower(trim((string) (config('webcontent.ai.no_thinking', false))));
        $truthy = in_array($mode, ['1', 'true', 'budget'], true);

        if ($truthy || $mode === 'both') {
            $body['reasoning_budget'] = 0;
        }

        if (in_array($mode, ['template', 'both'], true)) {
            $body['chat_template_kwargs'] = ['enable_thinking' => false];
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
        $content = $this->stripThinkBlocks($content);
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
     * DeepSeek-style templates emit chain-of-thought inline as
     * <think>…</think> (sometimes unclosed when generation is truncated).
     * The JSON answer, if any, lives AFTER the block — and the thinking text
     * itself often contains draft JSON braces, so it must go BEFORE any
     * JSON extraction.
     */
    protected function stripThinkBlocks(string $content): string
    {
        $content = preg_replace('/<think>.*?<\/think>/s', '', $content) ?? $content;

        // Unclosed <think> (truncated generation): everything from the tag
        // on is reasoning; there is no answer after it.
        $content = preg_replace('/<think>.*$/s', '', $content) ?? $content;

        return $content;
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
