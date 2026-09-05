<?php

use Illuminate\Support\Facades\Route;
use Kit\WebContent\Http\Controllers\PageController;
use Kit\WebContent\Http\Controllers\ProposalController;
use Kit\WebContent\Http\Controllers\SitemapController;

/*
|--------------------------------------------------------------------------
| Legacy /pages/{slug} redirect
|--------------------------------------------------------------------------
| Permanently redirects to the clean /{slug} URL. MUST be registered
| BEFORE the public catch-all route below.
*/
Route::get('/pages/{slug}', function (string $slug) {
    return redirect()->route('page.show', ['slug' => $slug], 301);
})->where('slug', '.+');

/*
|--------------------------------------------------------------------------
| Admin routes (always registered)
|--------------------------------------------------------------------------
| Authorization comes from config('webcontent.middleware') + the
| config('webcontent.gate') ability, applied by the service provider.
|
| Review page: normal admin protection.
| Approve/Discard: SIGNED urls only (email/Telegram clicks) — the signature
| + expiry is the authorization, no session required.
*/
$adminMiddleware = (array) config('webcontent.middleware', ['web']);

Route::middleware($adminMiddleware)->group(function () {
    Route::get('/web-content/proposals', [ProposalController::class, 'index'])
        ->name('webcontent.proposals.index');

    Route::get('/web-content/{webContent}/edit', [PageController::class, 'edit'])
        ->name('web-content.edit');
    Route::put('/web-content/{webContent}', [PageController::class, 'update'])
        ->name('web-content.update');
});

// `web` is included because implicit model binding runs inside the
// SubstituteBindings middleware of the web group; `signed` authorizes
// the click. No auth on purpose — see config('webcontent.notify').
Route::get('/web-content/proposals/{proposal}/approve', [ProposalController::class, 'approve'])
    ->middleware(['web', 'signed'])
    ->name('webcontent.proposals.approve');

Route::get('/web-content/proposals/{proposal}/discard', [ProposalController::class, 'discard'])
    ->middleware(['web', 'signed'])
    ->name('webcontent.proposals.discard');

/*
|--------------------------------------------------------------------------
| Public routes (optional)
|--------------------------------------------------------------------------
| GET {sitemap}    -> XML sitemap
| GET /            -> home page (config('webcontent.home_slug'))
| GET /{slug}      -> catch-all page renderer (name: page.show)
|
| The catch-all is intentionally registered LAST so any host route that was
| registered earlier wins. Disable via config('webcontent.register_public_routes').
*/
if (config('webcontent.register_public_routes', true)) {
    Route::get(config('webcontent.sitemap_path', 'sitemap.xml'), [SitemapController::class, 'show'])
        ->name('webcontent.sitemap');

    Route::get('/', [PageController::class, 'show']);
    Route::get('/{slug}', [PageController::class, 'show'])
        ->where('slug', '.*')
        ->name('page.show');
}
