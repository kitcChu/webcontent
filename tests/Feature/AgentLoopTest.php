<?php

namespace Kit\WebContent\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Kit\WebContent\Mail\ProposalReviewMail;
use Kit\WebContent\Models\ContentProposal;
use Kit\WebContent\Models\WebContent;
use Kit\WebContent\Tests\TestCase;

class AgentLoopTest extends TestCase
{
    protected function seedPage(): WebContent
    {
        return WebContent::create([
            'slug' => 'london-rate',
            'title' => 'London Removals Rate',
            'content' => '<article><h1>HK to London from £900</h1><p>Updated 2024.</p></article>',
            'locale' => 'en',
            'is_web_page' => true,
        ]);
    }

    protected function fakeAi(array $sequenceResponses): void
    {
        config([
            'webcontent.ai.api_key' => 'test-key',
            'webcontent.ai.base_url' => 'https://ai.example.com',
        ]);

        $sequence = Http::sequence();

        foreach ($sequenceResponses as $response) {
            $sequence->push([
                'choices' => [['message' => ['content' => json_encode($response, JSON_UNESCAPED_UNICODE)]]],
            ]);
        }

        Http::fake(['ai.example.com/*' => $sequence]);
    }

    protected function enableSearch(): void
    {
        config([
            'webcontent.search.provider' => 'tavily',
            'webcontent.search.tavily_api_key' => 'tavily-key',
        ]);

        Http::fake(['api.tavily.com/*' => Http::response([
            'results' => [
                ['title' => 'Current rates', 'url' => 'https://example.org/rates', 'content' => 'London from £1,100 in 2026'],
            ],
        ])]);
    }

    protected function updatePayload(array $overrides = []): array
    {
        return array_merge([
            'action' => 'update',
            'slug' => 'london-rate',
            'title' => 'London Removals Rate',
            'rationale' => 'Price changed from £900 to £1,100 per the 2026 source.',
            'confidence' => 0.9,
            'sources' => [['title' => 'Current rates', 'url' => 'https://example.org/rates']],
            'proposed' => [
                'title' => 'London Removals Rate',
                'content' => '<article><h1>HK to London from £1,100</h1><p>Updated 2026.</p></article>',
            ],
        ], $overrides);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function research_command_audits_pages_and_files_proposals(): void
    {
        $page = $this->seedPage();
        $this->enableSearch();

        // Call 1: search-query planning; call 2: audit proposals.
        $this->fakeAi([
            ['queries' => ['London removals price 2026']],
            ['proposals' => [$this->updatePayload()]],
        ]);

        config(['webcontent.notify.email' => 'owner@example.com']);
        Mail::fake();

        $this->artisan('webcontent:research')->assertExitCode(0);

        $proposal = ContentProposal::query()->sole();

        $this->assertSame('update', $proposal->action);
        $this->assertSame('london-rate', $proposal->slug);
        $this->assertSame(WebContent::latest('id')->first()->id, $proposal->web_content_id);
        $this->assertSame(0.9, (float) $proposal->confidence);
        $this->assertSame('pending', $proposal->status);

        // Owner is asked, nothing changed automatically.
        Mail::assertSent(ProposalReviewMail::class);
        $this->assertSame('<article><h1>HK to London from £900</h1><p>Updated 2024.</p></article>', $page->fresh()->content);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function research_command_sends_telegram_with_decision_links(): void
    {
        $this->seedPage();
        $this->enableSearch();
        $this->fakeAi([
            ['queries' => ['q']],
            ['proposals' => [$this->updatePayload()]],
        ]);

        config([
            'webcontent.notify.telegram_bot_token' => 'bot-token',
            'webcontent.notify.telegram_chat_id' => '42',
        ]);

        $this->artisan('webcontent:research')->assertExitCode(0);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'api.telegram.org/botbot-token/sendMessage')
                && str_contains($request['text'], 'UPDATE')
                && str_contains($request['text'], 'london-rate')
                && str_contains($request['text'], 'Approve');
        });
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function discovery_phase_proposes_new_pages_from_topics(): void
    {
        $this->seedPage();
        $this->enableSearch();

        config(['webcontent.agent.discovery_topics' => ['UK container storage trends 2026']]);

        // Call 1: queries for the page; call 2: audit (no changes);
        // call 3: discovery proposes a new page.
        $this->fakeAi([
            ['queries' => ['q']],
            ['proposals' => [['action' => 'none', 'slug' => 'london-rate', 'confidence' => 1.0]]],
            ['proposals' => [[
                'action' => 'add',
                'slug' => 'storage-price-guide-2026',
                'title' => 'Storage Price Guide 2026',
                'rationale' => 'High-demand topic not covered by the site.',
                'confidence' => 0.8,
                'sources' => [['title' => 'Trends', 'url' => 'https://example.org/trends']],
                'proposed' => [
                    'title' => 'Storage Price Guide 2026',
                    'content' => '<article><h1>Guide</h1></article>',
                    'locale' => 'en',
                ],
            ]]],
        ]);

        $this->artisan('webcontent:research')->assertExitCode(0);

        $proposal = ContentProposal::query()->where('action', 'add')->sole();
        $this->assertSame('storage-price-guide-2026', $proposal->slug);
        $this->assertNull($proposal->web_content_id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function low_confidence_and_none_actions_are_skipped(): void
    {
        $this->seedPage();
        config(['webcontent.ai.api_key' => 'test-key', 'webcontent.ai.base_url' => 'https://ai.example.com']);

        // Searcher disabled: single AI call per page.
        $this->fakeAi([
            ['proposals' => [
                ['action' => 'none', 'slug' => 'london-rate', 'confidence' => 1.0],
                $this->updatePayload(['confidence' => 0.3]),
                $this->updatePayload(['confidence' => 0.95, 'rationale' => 'sure.']),
            ]],
        ]);

        $this->artisan('webcontent:research', ['--no-notify' => true])->assertExitCode(0);

        // Only the high-confidence proposal survives.
        $this->assertSame(1, ContentProposal::query()->count());
        $this->assertSame('sure.', ContentProposal::query()->sole()->rationale);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function duplicate_pending_proposal_is_updated_not_duplicated(): void
    {
        $this->seedPage();
        config(['webcontent.ai.api_key' => 'test-key', 'webcontent.ai.base_url' => 'https://ai.example.com']);

        // Same AI answer on every audit call (searcher disabled → 1 call per run).
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'ai.example.com')) {
                return Http::response([
                    'choices' => [['message' => ['content' => json_encode(['proposals' => [$this->updatePayload()]])]]],
                ]);
            }

            return Http::response([], 500);
        });

        $this->artisan('webcontent:research', ['--no-notify' => true]);
        $this->artisan('webcontent:research', ['--no-notify' => true]);

        // Second run refreshed the same pending proposal instead of duplicating it.
        $this->assertSame(1, ContentProposal::query()->count());
        $this->assertSame('pending', ContentProposal::query()->sole()->status);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function dry_run_prints_but_does_not_persist(): void
    {
        $this->seedPage();
        config(['webcontent.ai.api_key' => 'test-key', 'webcontent.ai.base_url' => 'https://ai.example.com']);
        $this->fakeAi([['proposals' => [$this->updatePayload()]]]);

        $this->artisan('webcontent:research', ['--dry-run' => true]);

        $this->assertSame(0, ContentProposal::query()->count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function a_failed_ai_reply_does_not_abort_the_run(): void
    {
        // Two pages; the first gets garbage (local-LLM style), the second a
        // valid proposal. The run must survive and still file the second.
        WebContent::create(['slug' => 'page-a', 'title' => 'A', 'content' => 'a', 'is_web_page' => true]);
        $pageB = WebContent::create(['slug' => 'page-b', 'title' => 'B', 'content' => 'b', 'is_web_page' => true]);

        config(['webcontent.ai.api_key' => 'test-key', 'webcontent.ai.base_url' => 'https://ai.example.com']);
        $this->fakeAi([
            ['proposals' => 'not-an-array'],                       // page A: malformed
            ['proposals' => [$this->updatePayload([                 // page B: fine
                'slug' => 'page-b', 'rationale' => 'b ok', 'confidence' => 0.9,
                'proposed' => ['content' => 'B updated'],
            ])]],
        ]);

        $this->artisan('webcontent:research', ['--no-notify' => true])->assertExitCode(0);

        $this->assertSame(1, ContentProposal::query()->count());
        $this->assertSame('page-b', ContentProposal::query()->sole()->slug);
        $this->assertNull($pageB->fresh()->style); // nothing applied without approval
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function json_mode_can_be_disabled_for_servers_without_support(): void
    {
        $this->seedPage();
        config([
            'webcontent.ai.api_key' => 'test-key',
            'webcontent.ai.base_url' => 'https://ai.example.com',
            'webcontent.ai.json_mode' => false,
        ]);
        $this->fakeAi([['proposals' => [$this->updatePayload()]]]);

        $this->artisan('webcontent:research', ['--no-notify' => true])->assertExitCode(0);

        Http::assertSent(fn ($request) => !isset($request['response_format']));
        $this->assertSame(1, ContentProposal::query()->count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function reasoning_models_with_empty_content_are_parsed_from_reasoning_content(): void
    {
        $this->seedPage();
        config([
            'webcontent.ai.api_key' => 'test-key',
            'webcontent.ai.base_url' => 'https://ai.example.com',
            'webcontent.ai.json_mode' => false,
        ]);

        // Qwen3/R1-style reply: nothing in `content`, the JSON draft is
        // buried inside chain-of-thought prose.
        Http::fake(['ai.example.com/*' => Http::response([
            'choices' => [['message' => [
                'role' => 'assistant',
                'content' => '',
                'reasoning_content' => 'We need to audit the page. The price is outdated. '
                    .'So the proposal is {"proposals":[{"action":"update","slug":"london-rate",'
                    .'"rationale":"New price","confidence":0.9,"proposed":{"content":"<p>New</p>"}}]} '
                    .'That covers it.',
            ]]],
        ])]);

        $this->artisan('webcontent:research', ['--no-notify' => true])->assertExitCode(0);

        $this->assertSame(1, ContentProposal::query()->count());
        $this->assertSame('New price', ContentProposal::query()->sole()->rationale);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function no_thinking_switch_is_sent_when_enabled(): void
    {
        $this->seedPage();
        config([
            'webcontent.ai.api_key' => 'test-key',
            'webcontent.ai.base_url' => 'https://ai.example.com',
            'webcontent.ai.no_thinking' => true,
        ]);
        $this->fakeAi([['proposals' => []]]);

        $this->artisan('webcontent:research', ['--no-notify' => true])->assertExitCode(0);

        Http::assertSent(fn ($request) => $request['reasoning_budget'] === 0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function signed_approve_link_applies_the_update(): void
    {
        $page = $this->seedPage();

        $proposal = ContentProposal::create([
            'web_content_id' => $page->id,
            'action' => 'update',
            'slug' => 'london-rate',
            'rationale' => 'New price.',
            'proposed' => ['content' => '<article>New 2026 content</article>'],
            'confidence' => 0.9,
        ]);

        $url = URL::temporarySignedRoute('webcontent.proposals.approve', now()->addDay(), ['proposal' => $proposal->id]);

        $response = $this->get($url);

        $response->assertOk();
        $this->assertSame('applied', $proposal->refresh()->status);
        $this->assertStringContainsString('New 2026 content', $page->fresh()->content);
        $this->assertNotNull($proposal->applied_at);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function signed_discard_link_rejects_without_touching_the_page(): void
    {
        $page = $this->seedPage();
        $original = $page->content;

        $proposal = ContentProposal::create([
            'web_content_id' => $page->id,
            'action' => 'remove',
            'slug' => 'london-rate',
            'rationale' => 'Obsolete.',
        ]);

        $url = URL::temporarySignedRoute('webcontent.proposals.discard', now()->addDay(), ['proposal' => $proposal->id]);

        $this->get($url)->assertOk();

        $this->assertSame('rejected', $proposal->refresh()->status);
        $this->assertNull($page->fresh()->deleted_at);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function unsigned_links_are_rejected(): void
    {
        $proposal = ContentProposal::create([
            'action' => 'update',
            'slug' => 'x',
            'rationale' => 'r',
        ]);

        $this->get("/web-content/proposals/{$proposal->id}/approve")->assertForbidden();
        $this->get("/web-content/proposals/{$proposal->id}/discard")->assertForbidden();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function approving_add_and_remove_proposals_creates_and_soft_deletes(): void
    {
        // ADD
        $add = ContentProposal::create([
            'action' => 'add',
            'slug' => 'faq-2026',
            'title' => 'FAQ',
            'rationale' => 'Gap.',
            'proposed' => ['title' => 'FAQ 2026', 'content' => '<article>FAQ</article>', 'locale' => 'en'],
        ]);

        $add->apply();

        $page = WebContent::query()->where('slug', 'faq-2026')->first();
        $this->assertNotNull($page);
        $this->assertTrue($page->is_web_page);
        $this->assertSame('applied', $add->fresh()->status);

        // REMOVE (soft delete, reversible)
        $remove = ContentProposal::create([
            'web_content_id' => $page->id,
            'action' => 'remove',
            'slug' => 'faq-2026',
            'rationale' => 'Superseded.',
        ]);

        $remove->apply();

        $this->assertSoftDeleted($page);
        $this->assertSame('applied', $remove->fresh()->status);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function approving_twice_is_blocked(): void
    {
        $page = $this->seedPage();
        $proposal = ContentProposal::create([
            'web_content_id' => $page->id,
            'action' => 'update',
            'slug' => 'london-rate',
            'rationale' => 'r',
            'proposed' => ['content' => 'New'],
        ]);

        $url = URL::temporarySignedRoute('webcontent.proposals.approve', now()->addDay(), ['proposal' => $proposal->id]);

        $this->get($url)->assertOk();
        $this->get($url)->assertOk(); // second click shows the "already applied" page

        $this->assertSame('applied', $proposal->refresh()->status);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function review_page_lists_proposals_and_requires_the_gate(): void
    {
        config(['webcontent.gate' => 'manage-web-content']);
        \Illuminate\Support\Facades\Gate::define('manage-web-content', fn ($user = null) => false);

        $this->get('/web-content/proposals')->assertForbidden();

        \Illuminate\Support\Facades\Gate::define('manage-web-content', fn ($user = null) => true);
        // Gate::define replaces the previous definition.

        $this->get('/web-content/proposals')->assertOk()->assertSee('agent proposals');
    }
}
