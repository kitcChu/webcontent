<?php

namespace Kit\WebContent\Services;

use Illuminate\Support\Collection;
use Kit\WebContent\Models\ContentProposal;
use Kit\WebContent\Models\WebContent;

/**
 * The scheduled content-maintenance agent.
 *
 * Per run (never mutates web_contents — it only creates ContentProposal rows
 * the owner approves):
 *
 *  1. AUDIT  — for each live page: ask the AI which fresh data to look up,
 *              run those web searches, then propose update/remove/none with
 *              a rationale, sources and confidence.
 *  2. DISCOVER — search the configured discovery topics and propose NEW
 *              pages that would fill gaps in the site.
 *
 * Low-confidence and duplicate pending proposals are filtered out.
 */
class ContentResearchAgent
{
    public function __construct(
        protected AiClient $ai,
        protected WebSearcher $searcher,
    ) {}

    /**
     * Run a full research pass.
     *
     * @param  string|null  $slug    audit a single page only
     * @param  int|null     $limit   max pages audited this run
     * @param  bool         $persist false = dry run, returns payloads only
     * @return array{proposals: Collection, pages_audited: int, skipped: int, dry_run: bool}
     */
    public function run(?string $slug = null, ?int $limit = null, bool $persist = true): array
    {
        $limit ??= (int) config('webcontent.agent.max_pages_per_run', 5);

        $pages = WebContent::query()
            ->webPage()
            ->when($slug !== null, fn ($q) => $q->where('slug', $slug))
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        $payloads = [];
        $skipped = 0;

        foreach ($pages as $page) {
            foreach ($this->auditPage($page) as $payload) {
                if ($this->shouldSkip($payload)) {
                    $skipped++;
                    continue;
                }
                $payloads[] = ['payload' => $payload, 'page' => $page];
            }
        }

        // Discovery is skipped when auditing a single page.
        if ($slug === null) {
            foreach ($this->discover() as $payload) {
                if ($this->shouldSkip($payload)) {
                    $skipped++;
                    continue;
                }
                $payloads[] = ['payload' => $payload, 'page' => null];
            }
        }

        $proposals = $persist
            ? $this->persist($payloads)
            : collect($payloads)->map(fn ($item) => $item['payload']);

        return [
            'proposals' => $proposals,
            'pages_audited' => $pages->count(),
            'skipped' => $skipped,
            'dry_run' => !$persist,
        ];
    }

    /**
     * Audit one page: generate search queries, fetch fresh data, propose.
     *
     * @return array<int, array> proposal payloads (may be empty = no change)
     */
    public function auditPage(WebContent $page): array
    {
        $searchResults = [];

        if ($this->searcher->enabled()) {
            $queries = $this->planQueries($page);
            $searchResults = $this->searcher->searchAll($queries);
        }

        $response = $this->ai->chatJson([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->auditPrompt($page, $searchResults)],
        ]);

        $proposals = $response['proposals'] ?? [];

        return is_array($proposals) ? array_values($proposals) : [];
    }

    /**
     * Propose brand-new pages from the configured discovery topics.
     */
    public function discover(): array
    {
        $topics = (array) config('webcontent.agent.discovery_topics', []);

        if ($topics === [] || !$this->searcher->enabled()) {
            return [];
        }

        $results = $this->searcher->searchAll($topics);

        if ($results === []) {
            return [];
        }

        $response = $this->ai->chatJson([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->discoveryPrompt($topics, $results)],
        ]);

        $proposals = $response['proposals'] ?? [];

        return is_array($proposals) ? array_values($proposals) : [];
    }

    /**
     * Turn payloads into pending ContentProposal rows, skipping duplicates
     * (same slug+action already pending).
     *
     * @param  array<int, array{payload: array, page: WebContent|null}>  $items
     * @return Collection<int, ContentProposal>
     */
    protected function persist(array $items): Collection
    {
        return collect($items)
            ->map(function (array $item) {
                $payload = $item['payload'];
                $action = strtolower((string) ($payload['action'] ?? ''));

                if (!in_array($action, [ContentProposal::ACTION_ADD, ContentProposal::ACTION_UPDATE, ContentProposal::ACTION_REMOVE], true)) {
                    return null;
                }

                $existing = ContentProposal::query()
                    ->where('slug', (string) ($payload['slug'] ?? ''))
                    ->where('action', $action)
                    ->where('status', ContentProposal::STATUS_PENDING)
                    ->first();

                $attributes = $this->attributesFrom($payload, $action, $item['page']);

                return $existing
                    ? tap($existing, fn ($p) => $p->update($attributes))
                    : ContentProposal::query()->create($attributes);
            })
            ->filter()
            ->values();
    }

    protected function attributesFrom(array $payload, string $action, ?WebContent $page): array
    {
        return [
            'web_content_id' => $page?->id,
            'action' => $action,
            'slug' => (string) ($payload['slug'] ?? $page?->slug ?? ''),
            'title' => $payload['title'] ?? $payload['proposed']['title'] ?? $page?->title,
            'rationale' => (string) ($payload['rationale'] ?? ''),
            'proposed' => isset($payload['proposed']) && is_array($payload['proposed'])
                ? $payload['proposed']
                : null,
            'sources' => isset($payload['sources']) && is_array($payload['sources'])
                ? $payload['sources']
                : null,
            'confidence' => isset($payload['confidence']) && is_numeric($payload['confidence'])
                ? (float) $payload['confidence']
                : null,
            'status' => ContentProposal::STATUS_PENDING,
        ];
    }

    protected function shouldSkip(array $payload): bool
    {
        $action = strtolower((string) ($payload['action'] ?? ''));

        if ($action === 'none' || $action === '') {
            return true;
        }

        $min = (float) config('webcontent.agent.min_confidence', 0.6);
        $confidence = is_numeric($payload['confidence'] ?? null) ? (float) $payload['confidence'] : 1.0;

        return $confidence < $min;
    }

    protected function planQueries(WebContent $page): array
    {
        $max = (int) config('webcontent.agent.max_search_queries_per_page', 3);

        $response = $this->ai->chatJson([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => implode("\n", [
                'Website page:',
                json_encode([
                    'slug' => $page->slug,
                    'title' => $page->title,
                    'last_updated' => optional($page->updated_at)->toDateString(),
                ], JSON_UNESCAPED_UNICODE),
                '',
                "Propose up to {$max} web search queries that would surface CURRENT data (prices, dates, regulations, statistics, opening hours, staff) relevant to this page and could make it outdated.",
                'Respond with JSON: {"queries": ["...", "..."]}',
            ])],
        ], 0.4);

        $queries = $response['queries'] ?? [];

        return collect(is_array($queries) ? $queries : [])
            ->filter(fn ($q) => is_string($q) && $q !== '')
            ->take($max)
            ->values()
            ->all();
    }

    protected function systemPrompt(): string
    {
        return implode(' ', [
            'You are a meticulous website content-maintenance agent for a moving & storage company.',
            'You never invent facts: every proposed claim must be supported by the supplied search results or clearly marked as to-verify.',
            'You always respond with a single JSON object and nothing else.',
        ]);
    }

    protected function auditPrompt(WebContent $page, array $searchResults): string
    {
        $chars = (int) config('webcontent.agent.content_chars_sent_to_ai', 6000);

        return implode("\n", [
            'Audit the page below against the current-date search results and propose changes.',
            '',
            'CURRENT PAGE:',
            json_encode([
                'slug' => $page->slug,
                'title' => $page->title,
                'locale' => $page->locale,
                'last_updated' => optional($page->updated_at)->toAtomString(),
                'content' => mb_substr((string) $page->content, 0, $chars),
            ], JSON_UNESCAPED_UNICODE),
            '',
            'SEARCH RESULTS:',
            json_encode($searchResults, JSON_UNESCAPED_UNICODE),
            '',
            'Rules:',
            '- action "update": content is outdated or wrong; "proposed" carries the COMPLETE replacement fields (content = full new HTML, not a diff; keep the page language).',
            '- action "remove": content is obsolete or misleading and should not exist; explain the impact in rationale.',
            '- action "none": page is still accurate (preferred when unsure).',
            '- Every proposal needs rationale, confidence (0-1) and sources.',
            '',
            'Respond with JSON: {"proposals": [{"action": "update|remove|none", "slug": "...", "title": "...", "rationale": "...", "confidence": 0.0, "sources": [{"title": "...", "url": "..."}], "proposed": {"title": "...", "content": "...", "style": null, "script": null, "head_meta": {}, "locale": "en"}}]}',
        ]);
    }

    protected function discoveryPrompt(array $topics, array $searchResults): string
    {
        $index = WebContent::query()->webPage()->get(['slug', 'title', 'updated_at']);

        return implode("\n", [
            'Our website currently has these pages:',
            json_encode($index, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '',
            'We researched these topics:',
            json_encode(['topics' => $topics, 'results' => $searchResults], JSON_UNESCAPED_UNICODE),
            '',
            'Propose NEW pages (action "add") that fill genuine gaps in the site, based on the research. Do not duplicate existing slugs.',
            'For each: unique slug, title, rationale why it helps visitors, confidence, sources, and "proposed" containing title + full starter content (HTML, in the site language) + optional head_meta + locale.',
            '',
            'Respond with JSON: {"proposals": [{"action": "add", "slug": "...", "title": "...", "rationale": "...", "confidence": 0.0, "sources": [{"title": "...", "url": "..."}], "proposed": {"title": "...", "content": "...", "head_meta": {}, "locale": "en"}}]}',
        ]);
    }
}
