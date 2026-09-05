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
    | The admin editor routes (edit / update) run through this middleware stack
    | and, when set, through this Laravel gate ability.
    |
    |   Gate::define('manage-web-content', fn ($user) => $user->is_admin);
    |
    | Set `gate` to null to skip the ability check entirely (NOT recommended
    | for production).
    */
    'middleware' => ['web', 'auth'],
    'gate' => 'manage-web-content',

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
