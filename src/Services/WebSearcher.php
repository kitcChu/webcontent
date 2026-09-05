<?php

namespace Kit\WebContent\Services;

use Illuminate\Support\Facades\Http;

/**
 * Pluggable web search. Ships with a Tavily driver; set
 * `webcontent.search.provider` to `none` to run the agent on model knowledge
 * only (then it can still flag stale content but has no fresh web data).
 */
class WebSearcher
{
    public function enabled(): bool
    {
        return config('webcontent.search.provider') === 'tavily'
            && (bool) config('webcontent.search.api_key');
    }

    /**
     * @return array<int, array{title: string, url: string, snippet: string}>
     */
    public function search(string $query): array
    {
        if (!$this->enabled()) {
            return [];
        }

        $response = Http::timeout((int) config('webcontent.ai.timeout', 120))
            ->post('https://api.tavily.com/search', [
                'api_key' => config('webcontent.search.api_key'),
                'query' => $query,
                'search_depth' => 'basic',
                'max_results' => (int) config('webcontent.search.max_results', 5),
            ]);

        if ($response->failed()) {
            return []; // research is best-effort; never abort the run over search
        }

        return collect($response->json('results', []))
            ->map(fn ($r) => [
                'title' => (string) ($r['title'] ?? ''),
                'url' => (string) ($r['url'] ?? ''),
                'snippet' => mb_substr((string) ($r['content'] ?? ''), 0, 500),
            ])
            ->filter(fn ($r) => $r['url'] !== '')
            ->values()
            ->all();
    }

    /**
     * Run several queries and merge de-duplicated results.
     */
    public function searchAll(array $queries): array
    {
        $seen = [];
        $all = [];

        foreach ($queries as $query) {
            foreach ($this->search((string) $query) as $result) {
                if (isset($seen[$result['url']])) {
                    continue;
                }
                $seen[$result['url']] = true;
                $all[] = $result;
            }
        }

        return $all;
    }
}
