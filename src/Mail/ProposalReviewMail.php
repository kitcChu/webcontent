<?php

namespace Kit\WebContent\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Kit\WebContent\Models\ContentProposal;

class ProposalReviewMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public $proposals,
        public string $reviewUrl,
    ) {}

    public function build(): self
    {
        return $this
            ->subject(sprintf(
                '[WebContent] %d proposed change(s) awaiting your approval',
                $this->proposals->count()
            ))
            ->markdown('webcontent::emails.proposal-review');
    }
}
