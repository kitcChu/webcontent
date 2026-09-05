<?php

use Illuminate\Support\Facades\Route;
use Kit\WebContent\Http\Controllers\PageController;
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
| Admin editor routes (always registered)
|--------------------------------------------------------------------------
| Authorization comes from config('webcontent.middleware') + the
| config('webcontent.gate') ability, applied by the service provider.
*/
Route::middleware((array) config('webcontent.middleware', ['web']))
    ->group(function () {
        Route::get('/web-content/{webContent}/edit', [PageController::class, 'edit'])
            ->name('web-content.edit');
        Route::put('/web-content/{webContent}', [PageController::class, 'update'])
            ->name('web-content.update');
    });

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
