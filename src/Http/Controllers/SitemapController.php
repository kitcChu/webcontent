<?php

namespace Kit\WebContent\Http\Controllers;

use Illuminate\Routing\Controller;
use Kit\WebContent\Models\WebContent;

class SitemapController extends Controller
{
    public function show()
    {
        // All servable pages (is_web_page = true).
        $pages = WebContent::query()->where('is_web_page', true)->get();

        $urls = [];

        // Homepage first.
        $urls[] = [
            'loc' => url('/'),
            'lastmod' => now()->toAtomString(),
            'priority' => '1.0',
        ];

        // Dynamic CMS pages.
        foreach ($pages as $page) {
            // Skip the homepage slug; it is already in the list.
            if ($page->slug === config('webcontent.home_slug', 'main')) {
                continue;
            }

            // Use updated_at, fallback to created_at, then to now().
            $lastmod = $page->updated_at ?? $page->created_at ?? now();

            $urls[] = [
                'loc' => route('page.show', ['slug' => $page->slug]),
                'lastmod' => $lastmod->toAtomString(),
                'priority' => '0.7',
            ];
        }

        return response()
            ->view('webcontent::sitemap', ['urls' => $urls])
            ->header('Content-Type', 'text/xml');
    }
}
