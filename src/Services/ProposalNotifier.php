<?php

namespace Kit\WebContent\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Kit\WebContent\Mail\ProposalReviewMail;
use Kit\WebContent\Models\ContentProposal;

/**
 * Asks the owner for a decision: sends the pending proposals by email and/or
 * Telegram, each with signed Approve/Discard links (no login needed to click
 * them — the signature is the authorization and they expire).
 */
class ProposalNotifier
{
    public function notify($proposals): void
    {
        $proposals = collect($proposals);

        if ($proposals->isEmpty()) {
            return;
        }

        $email = config('webcontent.notify.email');
        $botToken = config('webcontent.notify.telegram_bot_token');
        $chatId = config('webcontent.notify.telegram_chat_id');

        if ($email) {
            Mail::to($email)->send(new ProposalReviewMail($proposals, $this->reviewUrl()));
        }

        if ($botToken && $chatId) {
            $this->sendTelegram($botToken, (string) $chatId, $this->telegramMessage($proposals));
        }
        if (!$email && (!$botToken || !$chatId)) {
            logger()->warning('webcontent: agent produced proposals but no notification channel is configured (webcontent.notify.*)');
        }
    }

    public function approveUrl(ContentProposal $proposal): string
    {
        return $this->signedUrl('webcontent.proposals.approve', $proposal);
    }

    public function discardUrl(ContentProposal $proposal): string
    {
        return $this->signedUrl('webcontent.proposals.discard', $proposal);
    }

    public function reviewUrl(): string
    {
        return route('webcontent.proposals.index');
    }

    protected function signedUrl(string $route, ContentProposal $proposal): string
    {
        $days = (int) config('webcontent.notify.links_expire_days', 7);

        return URL::temporarySignedRoute($route, now()->addDays(max($days, 1)), [
            'proposal' => $proposal->id,
        ]);
    }

    /**
     * Telegram message: one block per proposal with its decision links.
     * Returned as <=4096-character chunks (Telegram limit).
     */
    protected function telegramMessage($proposals): array
    {
        $header = sprintf(
            "🤖 <b>WebContent agent</b> proposes %d change(s).\nNothing was changed yet — your approval is required.\n",
            $proposals->count()
        );

        $blocks = $proposals->map(function (ContentProposal $proposal) {
            $icon = ['add' => '➕', 'update' => '✏️', 'remove' => '🗑'][$proposal->action] ?? '•';

            $lines = [
                sprintf('%s <b>%s</b> — <b>%s</b>', $icon, strtoupper($proposal->action), e($proposal->slug)),
                Str::limit(e($proposal->rationale ?: ''), 220),
                sprintf('Confidence: %s', $proposal->confidence !== null ? round((float) $proposal->confidence, 2) : 'n/a'),
                sprintf('<a href="%s">✅ Approve</a> · <a href="%s">🗑 Discard</a>', e($this->approveUrl($proposal)), e($this->discardUrl($proposal))),
            ];

            return implode("\n", $lines);
        })->all();

        return $this->chunk($header, $blocks);
    }

    /**
     * @return array<int, string> message chunks ready to send
     */
    protected function chunk(string $header, array $blocks): array
    {
        $chunks = [];
        $current = $header;

        foreach ($blocks as $block) {
            if (mb_strlen($current."\n\n".$block) > 3900) {
                $chunks[] = $current;
                $current = $block;
                continue;
            }
            $current .= "\n\n".$block;
        }

        if (trim($current) !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    protected function sendTelegram(string $botToken, string $chatId, array $chunks): void
    {
        foreach ($chunks as $text) {
            Http::asJson()->timeout(15)->post(
                "https://api.telegram.org/bot{$botToken}/sendMessage",
                [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]
            );
        }
    }
}
