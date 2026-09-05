<?php

namespace Kit\WebContent\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Kit\WebContent\Models\WebContent;

class PageController extends Controller
{
    public function edit(WebContent $webContent)
    {
        $this->authorizeAdmin();

        return Inertia::render('CMS/WebContent', [
            'title' => $webContent->title,
            'slug' => $webContent->slug,
            'locale' => $webContent->locale,
            'content' => $webContent->content,
            'style' => $webContent->style,
            'script' => $webContent->script,
            'head_meta' => $webContent->head_meta,
            'id' => $webContent->id,
            'csrf_token' => csrf_token(),
            'success' => session('success'),
            'errors' => session('errors') ? session('errors')->all() : [],
        ]);
    }

    public function update(Request $request, WebContent $webContent)
    {
        $this->authorizeAdmin();

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => ['required', 'string', 'max:255', 'unique:web_contents,slug,' . $webContent->id,
                function (string $attr, mixed $value, \Closure $fail) {
                    $firstSegment = explode('/', (string) $value)[0];
                    if (in_array(strtolower($firstSegment), config('webcontent.reserved', []), true)) {
                        $fail('The slug "' . $firstSegment . '" is reserved and cannot be used for a page.');
                    }
                }],
            'content' => 'required|string',
            'style' => 'nullable|string',
            'script' => 'nullable|string',
            'locale' => 'nullable|string|in:en',
            'head_meta' => 'nullable|string',
        ]);

        // Normalize locale: store 'en' for English pages, NULL otherwise
        // (NULL is treated as the default locale by the frontend).
        $validated['locale'] = ($validated['locale'] ?? null) === 'en' ? 'en' : null;

        // head_meta is edited as a JSON string in the admin UI; decode it
        // (or null it out) before persisting.
        $validated['head_meta'] = $this->decodeHeadMeta($validated['head_meta'] ?? null);

        $webContent->update($validated);

        return redirect()->back();
    }

    public function show(string $slug = null)
    {
        $slug = $slug ?: config('webcontent.home_slug', 'main');

        // Only content rows (is_web_page=1) are servable as pages; this also
        // prevents a stray slug from matching a form-fragment row.
        $pageContent = WebContent::webPage()
            ->where('slug', $slug)
            ->firstOrFail();

        // Share the page's head_meta with the Inertia root view so SEO tags
        // (title/description/OG/Twitter/canonical/hreflang/JSON-LD) can be
        // emitted SERVER-SIDE in <head>. See README for the root-view snippet.
        view()->share('webcontent_seo_head', $pageContent->head_meta);

        // Set the active locale for this page ('en' pages vs the default).
        if ($pageContent->locale === 'en') {
            app()->setLocale('en');
        }

        return Inertia::render('CMS/StaticPage', [
            'page_id' => $pageContent->id,
            'title' => $pageContent->title,
            'slug' => $pageContent->slug,
            'content' => $pageContent->content,
            'style' => $pageContent->style,
            'script' => $pageContent->script,
            'head_meta' => $pageContent->head_meta,
            'attach_form' => $pageContent->attachedForm?->content,
            'csrf_token' => csrf_token(),
            'success' => session('success'),
            'errors' => session('errors') ? session('errors')->all() : [],
        ]);
    }

    /**
     * Enforce the configured middleware-independent authorization: the
     * `webcontent.gate` ability (when configured) must pass.
     */
    protected function authorizeAdmin(): void
    {
        $ability = config('webcontent.gate');

        if ($ability) {
            Gate::authorize($ability, WebContent::class);
        } elseif (! Auth::check()) {
            abort(403, 'Authentication required.');
        }
    }

    /**
     * Decode the head_meta JSON string submitted from the admin editor into an
     * array (or null when empty/invalid). Invalid JSON is silently dropped to
     * keep the editor forgiving.
     */
    private function decodeHeadMeta(mixed $value): ?array
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }
}
