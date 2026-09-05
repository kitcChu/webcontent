# kitcchu/webcontent

A self-contained, database-backed CMS for Laravel + Inertia (Vue 3) apps, extracted
from a production codebase. Any Laravel 11/12 project can require it and get:

- **`web_contents` table + `WebContent` Eloquent model** — slugged pages carrying
  `content` (HTML), per-page `style` and `script` injection, `head_meta` JSON SEO
  (title/description/OG/Twitter/canonical/hreflang/JSON-LD), a `locale` flag
  (`en` or default), soft deletes and `created_by` / `updated_by` audit columns.
- **Form fragments** — rows with `is_web_page = false` can be attached to a page
  via `attach_form_id`; the renderer swaps `__CSRF_TOKEN__` placeholders and lays
  the form out inline, in a two-column "rate page" layout, or in a UIkit modal
  (pages opt in with a `href="#exportEnquiryForm"` CTA).
- **Public page serving** — `GET /` renders the home slug, `GET /{slug}` is a
  catch-all renderer for nested slugs (`services/packing`, `london-rate`, …),
  and legacy `/pages/{slug}` URLs 301-redirect to the clean URL. Reserved first
  segments (`orders`, `login`, …) can never be shadowed by page slugs.
- **Admin editor** — `GET /web-content/{id}/edit` renders a live-preview Inertia
  editor; `PUT /web-content/{id}` validates (title/slug uniqueness/reserved
  slugs/locale/head-meta JSON) and persists.
- **Sitemap** — `GET /sitemap.xml` lists the homepage plus every web page.
- **AI content agent (optional)** — a scheduled agent researches fresh data,
  audits every page and proposes `add` / `update` / `remove` changes. It
  **never touches `web_contents` by itself**: each proposal waits for your
  approval via email/Telegram (signed one-click links) or the review UI.

## Requirements

- PHP 8.2+, Laravel 11 or 12, `inertiajs/inertia-laravel` (v1/v2)
- Front end: Vue 3, `@inertiajs/vue3`, [UIkit](https://getuikit.com) (CSS + JS)
  wired into your app entry (the components use UIkit classes, modals and
  notifications)

## Installation

### 1. Require the package (via GitHub + Composer VCS)

Once this repository is pushed to GitHub (`https://github.com/kitcchu/webcontent`):

```bash
composer config repositories.webcontent vcs https://github.com/kitcchu/webcontent
composer require kitcchu/webcontent
```

For local development, use a path repository instead:

```json
// composer.json (host app)
{
    "repositories": [
        { "type": "path", "url": "../webcontent", "options": { "symlink": true } }
    ]
}
```

### 2. Migrate

The package ships one consolidated migration (auto-discovered via
`publishesMigrations`):

```bash
php artisan migrate
```

> Note: the schema merges what was previously four incremental migrations plus
> columns (`style`, `script`, `attach_form_id`, `is_web_page`) that only existed
> in the production database. Fresh installs get the full table in one step.

### 3. Publish the Vue components

The Inertia pages must live in the host app's JS tree so Vite can compile them:

```bash
php artisan vendor:publish --tag=webcontent-vue
```

This copies:

- `resources/js/Pages/CMS/WebContent.vue` — admin editor
- `resources/js/Pages/CMS/StaticPage.vue` — public page renderer
- `resources/js/Layouts/WebContentLayout.vue` — minimal layout used by both
  (swap its contents for your app's chrome after publishing, or point the
  imports at your own `AppLayout.vue`)

### 4. Publish the config (optional but recommended)

```bash
php artisan vendor:publish --tag=webcontent-config
```

### 5. Authorize the admin editor

Editor routes run through `config('webcontent.middleware')` (default
`['web', 'auth']`) and the `config('webcontent.gate')` ability. Define the gate
in your `AppServiceProvider`:

```php
Gate::define('manage-web-content', function ($user) {
    return in_array($user->id, [1, 4]);      // your admin rule
});
```

Set `'gate' => null` in the config to skip the ability check (not recommended),
or `'middleware' => ['web']` to skip authentication entirely.

## The AI agent in the loop

The package's maintenance loop — the agent proposes, **you** decide:

```
┌────────────┐   1. schedule      ┌─────────────────────────────────────┐
│ cron /     │ ─────────────────▶ │ webcontent:research                 │
│ scheduler  │                    │  2. for each page:                  │
└────────────┘                    │     a. AI picks search queries      │
                                  │     b. fresh data fetched (Tavily)  │
                                  │     c. AI proposes update/remove/none│
                                  │  3. discovery: searches configured  │
                                  │     topics → proposes NEW pages     │
                                  └──────────────┬──────────────────────┘
                                                 │ 4. proposals saved (pending)
                                                 ▼
                                  ┌─────────────────────────────────────┐
                                  │ ✉️  email + Telegram to YOU:        │
                                  │ "propose N changes" with            │
                                  │ [Approve] / [Discard] signed links  │
                                  └──────────────┬──────────────────────┘
                                                 │ 5. your click applies
                                                 ▼
                                  web_contents updated / created /
                                  soft-deleted (removals reversible)
```

Safety properties:

- **Nothing changes without an explicit approval.** The agent only writes
  `webcontent_proposals` rows.
- Decision links are **signed and expiring** (`links_expire_days`, default 7) —
  no login needed to click them from email/Telegram, but they cannot be
  forged. Low-confidence proposals (below `min_confidence`) and duplicates of
  already-pending proposals are dropped.
- `remove` proposals **soft-delete**, so any removal is reversible.

### Configuration (env)

```dotenv
WEBCONTENT_AGENT_SCHEDULE=true          # register the cron schedule
WEBCONTENT_AGENT_CRON="0 5 * * *"       # when to run (default 05:00, APP timezone!)

# Any OpenAI-compatible endpoint — cloud or LAN (llama.cpp, LM Studio, vLLM, proxy):
WEBCONTENT_AI_BASE_URL=http://llm.internal:8080/v1
WEBCONTENT_AI_MODEL=qwen-flash-next     # exact name served by the endpoint
WEBCONTENT_AI_API_KEY=local             # any non-empty value for LAN servers
WEBCONTENT_AI_TIMEOUT=1800              # slow boxes need generous timeouts
WEBCONTENT_AI_JSON_MODE=false           # off if the server lacks response_format
WEBCONTENT_AI_NO_THINKING=template      # false | budget | template | both (see below)

WEBCONTENT_SEARCH_PROVIDER=tavily       # none | tavily
WEBCONTENT_SEARCH_API_KEY=tvly-...

WEBCONTENT_NOTIFY_EMAIL=you@example.com
WEBCONTENT_TELEGRAM_BOT_TOKEN=123:abc
WEBCONTENT_TELEGRAM_CHAT_ID=42
```

Notes for self-hosted setups:

- **Thinking suppression modes** (`WEBCONTENT_AI_NO_THINKING`): the right
  switch depends on the server — `budget` sends `reasoning_budget: 0`
  (llama.cpp), `template` sends `chat_template_kwargs: {"enable_thinking":
  false}` (Qwen3-style templates; verified ~4× faster on a llama.cpp box),
  `both` sends both. Strict cloud APIs (OpenAI) reject unknown arguments —
  keep it `false` there.
- **Reasoning models are handled even when thinking stays on**: if a reply
  comes back with empty `content` and the JSON buried in `reasoning_content`
  (Qwen3 / DeepSeek-R1 style), or with inline `<think>…</think>` blocks, the
  client extracts the JSON. DeepSeek-R1-style templates cannot disable
  thinking via request at all — the parser is the safety net.
- **Routers/proxies**: point `WEBCONTENT_AI_BASE_URL` at the proxy and set
  `WEBCONTENT_AI_MODEL` to a name the proxy routes — a proxy that requires a
  model to route on will reject empty/omitted model names.

`discovery_topics` (config file) lists subjects the agent researches to propose
brand-new pages, e.g.:

```php
'discovery_topics' => [
    'UK self storage market prices 2026',
    'Hong Kong relocation regulations update',
],
```

### Running

```bash
php artisan webcontent:research            # audit + propose + ask you
php artisan webcontent:research --slug=london-rate
php artisan webcontent:research --dry-run  # print proposals, persist nothing
php artisan webcontent:research --no-notify
```

With `WEBCONTENT_AGENT_SCHEDULE=true` the command is registered on the Laravel
scheduler (remember to run `schedule:work` / the `schedule:run` cron).
Configure the AI/search keys first — the command aborts with a clear error
otherwise.

> **Timezone warning:** the cron string is interpreted in the **application
> timezone** (`APP_TIMEZONE`), not the server's clock. Laravel defaults to
> UTC — if your app has no `APP_TIMEZONE` set and you sleep in UTC+8, use
> `"0 21 * * *"` (21:00 UTC = 05:00 HKT), or set `APP_TIMEZONE` explicitly
> on a fresh app (changing it later shifts existing timestamp rendering).

### Reviewing

- **Email / Telegram** — each message lists the proposals with one-click
  `✅ Approve` / `🗑 Discard` signed links.
- **Review UI** — `/web-content/proposals` (protected by the same
  middleware + gate as the admin editor) lists pending and past decisions.
- **HTTP** — `GET /web-content/proposals/{id}/approve` (signed URL) also
  returns JSON with `Accept: application/json`.

## Front-end wiring

In `resources/js/app.js`:

```js
import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import 'uikit/dist/css/uikit.min.css';
import UIkit from 'uikit';
window.UIkit = UIkit;

createInertiaApp({
    resolve: (name) => require(`./Pages/${name}.vue`),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },
}).mount(el);
```

And in `vite.config.js`, make sure `resources/js/Pages/**/*.vue` are covered by
the Laravel plugin's default input (`resources/js/app.js`).

### Server-side SEO tags

`PageController@show` shares the page's `head_meta` with all views as
`$webcontent_seo_head`. Emit it server-side in your Inertia root view
(`resources/views/app.blade.php`) so crawlers see real tags:

```blade
<head>
    @if (isset($webcontent_seo_head))
        <title>{{ $webcontent_seo_head['title'] ?? '' }}</title>
        @foreach (['description', 'og:title', 'og:description', 'og:image',
                   'twitter:card', 'twitter:title', 'twitter:description'] as $key)
            @if (isset($webcontent_seo_head[$key]))
                <meta name="{{ str_starts_with($key, 'og:') ? 'property' : 'name' }}"
                      content="{{ $webcontent_seo_head[$key] }}">
            @endif
        @endforeach
        @if (isset($webcontent_seo_head['canonical']))
            <link rel="canonical" href="{{ url($webcontent_seo_head['canonical']) }}">
        @endif
        @foreach ($webcontent_seo_head['hreflang'] ?? [] as $lang => $u)
            <link rel="alternate" hreflang="{{ $lang }}" href="{{ url($u) }}">
        @endforeach
        @if (isset($webcontent_seo_head['ldjson']))
            <script type="application/ld+json">{{ $webcontent_seo_head['ldjson'] }}</script>
        @endif
    @endif
    @inertiaHead
</head>
```

(The `ldjson`/`hreflang` keys follow the source system's convention — adapt to
whatever structure you store in `head_meta`.)

## Using the model

```php
use Kit\WebContent\Models\WebContent;

// Pages only (excludes form fragments)
WebContent::webPage()->where('slug', 'services/packing')->firstOrFail();

// Create a page
WebContent::create([
    'slug' => 'about',
    'title' => 'About Us',
    'content' => '<article><h1>About</h1></article>',
    'locale' => 'en',                       // null = default locale
    'head_meta' => ['description' => '...', 'canonical' => '/about'],
    'is_web_page' => true,
]);

// Attach an enquiry form fragment to a page
$form = WebContent::create([
    'slug' => 'export-enquiry-form',
    'title' => 'Enquiry form',
    'content' => '<form id="exportEnquiryForm" action="/api/enquiry">…__CSRF_TOKEN__…</form>',
    'is_web_page' => false,
]);
$page->update(['attach_form_id' => $form->id]);
$page->attachedForm;   // => the fragment row
```

Renderer behaviour (from the source system):

- `__CSRF_TOKEN__` inside content/form HTML is replaced with the real CSRF token
- slugs ending in `-rate` with an attached form get the sticky two-column layout
- pages whose content contains `href="#exportEnquiryForm"` open the form in a
  UIkit modal; otherwise the form renders below the content

## Routes

| Method | URI                          | Name                | Notes                              |
|--------|------------------------------|---------------------|------------------------------------|
| GET    | `/web-content/proposals`     | `webcontent.proposals.index` | Agent proposal review UI (gated)   |
| GET    | `/web-content/proposals/{id}/approve` | `webcontent.proposals.approve` | SIGNED url, applies the proposal   |
| GET    | `/web-content/proposals/{id}/discard` | `webcontent.proposals.discard` | SIGNED url, rejects the proposal   |
| GET    | `/web-content/{id}/edit`     | `web-content.edit`  | Admin editor (middleware + gate)   |
| PUT    | `/web-content/{id}`          | `web-content.update`| Admin update (middleware + gate)   |
| GET    | `/pages/{slug}`              | —                   | 301 → `/{slug}`                    |
| GET    | `/sitemap.xml`               | `webcontent.sitemap`| XML sitemap (public routes on)     |
| GET    | `/`                          | —                   | Home slug (public routes on)       |
| GET    | `/{slug}`                    | `page.show`         | Catch-all renderer (public routes on) |

Disable the public routes with `'register_public_routes' => false` (e.g. when
your app maps `/` itself) — the model, editor and sitemap controller remain
usable directly.

## Testing

```bash
composer install
composer test
```

25 feature tests cover the schema, public rendering, form-fragment attachment,
the admin editor, audit columns, the sitemap and the full agent loop: research
and proposal filing, discovery of new pages, confidence/duplicate filtering,
email + Telegram delivery, signed-link security and apply/reject semantics.

## License

MIT
