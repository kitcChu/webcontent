<?php

return [

    /*
    |--------------------------------------------------------------------------
    | User model
    |--------------------------------------------------------------------------
    | Used by the HasAuditFields trait for the createdBy/updatedBy relations.
    */
    'user_model' => env('WEBCONTENT_USER_MODEL', 'App\\Models\\User'),

    /*
    |--------------------------------------------------------------------------
    | Admin authorization
    |--------------------------------------------------------------------------
    | The admin editor + proposal review routes run through this middleware
    | stack and, when set, through this Laravel gate ability.
    |
    |   Gate::define('manage-web-content', fn ($user) => $user->is_admin);
    |
    | Set `gate` to null to skip the ability check entirely (NOT recommended
    | for production). Approve/discard links sent by email/Telegram are
    | SIGNED urls and intentionally bypass login — the signature is the
    | authorization.
    */
    'middleware' => ['web', 'auth'],
    'gate' => 'manage-web-content',

    /*
    |--------------------------------------------------------------------------
    | AI agent
    |--------------------------------------------------------------------------
    | The agent audits pages, researches fresh data and files proposals.
    | It NEVER writes to web_contents by itself: proposals wait for approval.
    |
    | discovery_topics: searched once per run to propose NEW pages. Requires
    | the searcher (below) to be enabled.
    */
    'agent' => [
        'schedule_enabled' => env('WEBCONTENT_AGENT_SCHEDULE', false),
        'cron' => env('WEBCONTENT_AGENT_CRON', '0 5 * * *'),
        'max_pages_per_run' => (int) env('WEBCONTENT_AGENT_MAX_PAGES', 5),
        'max_search_queries_per_page' => 3,
        'discovery_topics' => [
            // 'UK self storage market prices 2026',
            // 'Hong Kong relocation regulations update',
        ],
        'content_chars_sent_to_ai' => 6000,
        'min_confidence' => 0.6, // proposals below are discarded silently
    ],

    /*
    |--------------------------------------------------------------------------
    | AI model (any OpenAI-compatible endpoint)
    |
    | Local LLM example (llama.cpp / LM Studio / vLLM):
    |   WEBCONTENT_AI_BASE_URL=http://llm.internal:8080/v1
    |   WEBCONTENT_AI_MODEL=<name your server serves>
    |   WEBCONTENT_AI_API_KEY=local        # any non-empty value; LAN servers ignore it
    |   WEBCONTENT_AI_TIMEOUT=1800         # slow boxes need a generous timeout    |
    | json_mode sends response_format={"type":"json_object"}. Servers without
    | JSON-grammar support can set WEBCONTENT_AI_JSON_MODE=false — the client
    | still parses JSON out of plain replies.
    |
    | no_thinking suppresses chain-of-thought on reasoning models — the
    | mechanism depends on the server:
    |   WEBCONTENT_AI_NO_THINKING=false       send nothing (default)
    |   WEBCONTENT_AI_NO_THINKING=budget      reasoning_budget=0 (llama.cpp)
    |   WEBCONTENT_AI_NO_THINKING=template    chat_template_kwargs.enable_thinking=false (Qwen3)
    |   WEBCONTENT_AI_NO_THINKING=both        both switches
    | Replies buried in reasoning_content or inline <think> blocks are parsed
    | as a fallback regardless. Do NOT enable against strict cloud APIs
    | (OpenAI rejects unknown arguments).
    */
    'ai' => [
        'base_url' => env('WEBCONTENT_AI_BASE_URL', 'https://api.deepseek.com'),
        'api_key' => env('WEBCONTENT_AI_API_KEY'),
        'model' => env('WEBCONTENT_AI_MODEL', 'deepseek-chat'),
        'timeout' => (int) env('WEBCONTENT_AI_TIMEOUT', 300),
        'temperature' => 0.2,
        'json_mode' => env('WEBCONTENT_AI_JSON_MODE', true),
        'no_thinking' => env('WEBCONTENT_AI_NO_THINKING', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Web search used by the agent ("find newer data")
    | provider: none | tavily  (https://tavily.com — free tier available)
    */
    'search' => [
        'provider' => env('WEBCONTENT_SEARCH_PROVIDER', 'none'),
        'tavily_api_key' => env('WEBCONTENT_TAVILY_API_KEY'),
        'max_results' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | "Ask me first" notifications
    |--------------------------------------------------------------------------
    | Configure either or both channels; signed approve/discard links are
    | included. Without any channel configured the proposals still wait in
    | the review UI and a warning is logged after each run.
    */
    'notify' => [
        'email' => env('WEBCONTENT_NOTIFY_EMAIL'),
        'telegram_bot_token' => env('WEBCONTENT_TELEGRAM_BOT_TOKEN'),
        'telegram_chat_id' => env('WEBCONTENT_TELEGRAM_CHAT_ID'),
        'links_expire_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Public routes
    |--------------------------------------------------------------------------
    | When true the package registers:
    |   GET /          -> page with slug 'main'
    |   GET /{slug}    -> catch-all page renderer (name: page.show)
    |   GET {sitemap}  -> XML sitemap of all pages
    | Disable if the host app already maps its own root/catch-all routes.
    */
    'register_public_routes' => true,
    'home_slug' => 'main',
    'sitemap_path' => 'sitemap.xml',

    /*
    |--------------------------------------------------------------------------
    | Reserved URL segments
    |--------------------------------------------------------------------------
    | Root segments a page slug must never use, so the catch-all GET /{slug}
    | can never shadow a functional route of the host app. The check uses the
    | FIRST path segment of a slug (e.g. "export-lite" from "export-lite/hk-to-uk")
    | so nested marketing URLs are allowed while "orders/..." is blocked.
    */
    'reserved' => [
        'login', 'register', 'logout', 'password',
        'dashboard', 'profile', 'auth', 'api',
        'web-content', 'cms', 'storage', 'img', 'assets',
        'orders', 'sitemap.xml', 'get-csrf-token',
    ],

];
