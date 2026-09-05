# kit/webcontent

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

## Requirements

- PHP 8.2+, Laravel 11 or 12, `inertiajs/inertia-laravel` (v1/v2)
- Front end: Vue 3, `@inertiajs/vue3`, [UIkit](https://getuikit.com) (CSS + JS)
  wired into your app entry (the components use UIkit classes, modals and
  notifications)

## Installation

### 1. Require the package (via GitHub + Composer VCS)

Once this repository is pushed to GitHub (`https://github.com/<you>/webcontent`):

```bash
composer config repositories.webcontent vcs https://github.com/<you>/webcontent
composer require kit/webcontent
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

13 feature tests cover the schema, public rendering, form-fragment attachment,
the admin editor (incl. gate denial, reserved slugs, head-meta JSON decoding),
audit columns and the sitemap.

## License

MIT
