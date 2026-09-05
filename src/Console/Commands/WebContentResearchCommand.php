<?php

namespace Kit\WebContent\Console\Commands;

use Illuminate\Console\Command;
use Kit\WebContent\Services\ContentResearchAgent;
use Kit\WebContent\Services\ProposalNotifier;

/**
 * The scheduled agent entry point. Run via cron/scheduler; with
 * webcontent.agent.schedule_enabled the service provider registers it on the
 * schedule automatically.
 */
class WebContentResearchCommand extends Command
{
    protected $signature = 'webcontent:research
        {--slug= : Audit only this page slug}
        {--limit= : Max pages to audit this run}
        {--dry-run : Print proposals without persisting or notifying}
        {--no-notify : Persist proposals but skip email/Telegram}';

    protected $description = 'AI agent: research fresh data, audit web_contents pages, propose add/update/remove changes for approval';

    public function handle(ContentResearchAgent $agent, ProposalNotifier $notifier): int
    {
        if (!config('webcontent.ai.api_key')) {
            $this->error('WEBCONTENT_AI_API_KEY is not set — the agent cannot run.');

            return self::FAILURE;
        }

        $this->info('🤖 WebContent agent: starting research pass…');

        $result = $agent->run(
            slug: $this->option('slug'),
            limit: $this->option('limit') ? (int) $this->option('limit') : null,
            persist: !$this->option('dry-run'),
        );

        $this->info(sprintf(
            'Audited %d page(s) — %d proposal(s), %d skipped, %d failed.',
            $result['pages_audited'],
            $result['proposals']->count(),
            $result['skipped'],
            $result['failed'],
        ));

        if ($result['failed'] > 0) {
            $this->warn('Some AI calls failed (logged); other pages were processed normally.');
        }

        foreach ($result['proposals'] as $proposal) {
            $payload = $proposal instanceof \Kit\WebContent\Models\ContentProposal ? $proposal->toArray() : $proposal;

            $this->line(sprintf(
                '  [%s] %s (confidence %s): %s',
                mb_strtoupper($payload['action'] ?? '?'),
                $payload['slug'] ?? '?',
                isset($payload['confidence']) ? round((float) $payload['confidence'], 2) : 'n/a',
                mb_strimwidth((string) ($payload['rationale'] ?? ''), 0, 120, '…'),
            ));
        }

        if ($result['dry_run']) {
            $this->warn('Dry run — nothing was persisted.');

            return self::SUCCESS;
        }

        if ($result['proposals']->isNotEmpty() && !$this->option('no-notify')) {
            $notifier->notify($result['proposals']);
            $this->info('Approval request sent (email and/or Telegram).');
        }

        return self::SUCCESS;
    }
}
