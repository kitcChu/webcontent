<?php

namespace Kit\WebContent\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Kit\WebContent\Models\ContentProposal;
use Kit\WebContent\Services\ProposalNotifier;

/**
 * Review page (gated like the admin editor) and the decision endpoints.
 *
 * Approve/Discard are reached through SIGNED urls (email / Telegram clicks),
 * so they deliberately do not require an authenticated session — the
 * signature and expiry are the authorization. The review index page, however,
 * sits behind the normal admin middleware + gate.
 */
class ProposalController extends Controller
{
    public function index()
    {
        $this->authorizeAdmin();

        $proposals = ContentProposal::query()
            ->whereIn('status', [ContentProposal::STATUS_PENDING, ContentProposal::STATUS_APPLIED, ContentProposal::STATUS_REJECTED])
            ->orderByDesc('created_at')
            ->paginate(25);

        $notifier = app(ProposalNotifier::class);

        return view('webcontent::proposals.index', [
            'proposals' => $proposals,
            'approveUrl' => fn ($p) => $notifier->approveUrl($p),
            'discardUrl' => fn ($p) => $notifier->discardUrl($p),
            'success' => session('success'),
            'error' => session('error'),
        ]);
    }

    public function approve(Request $request, ContentProposal $proposal)
    {
        return $this->decide($proposal, approve: true);
    }

    public function discard(Request $request, ContentProposal $proposal)
    {
        return $this->decide($proposal, approve: false);
    }

    protected function decide(ContentProposal $proposal, bool $approve)
    {
        if ($proposal->status !== ContentProposal::STATUS_PENDING) {
            return $this->respond($proposal, null, sprintf(
                'This proposal was already %s.', $proposal->status
            ));
        }

        try {
            if ($approve) {
                $proposal->apply();
                $message = sprintf('Applied: %s [%s].', strtoupper($proposal->action), $proposal->slug);
            } else {
                $proposal->reject();
                $message = sprintf('Discarded: %s [%s].', strtoupper($proposal->action), $proposal->slug);
            }
        } catch (\Throwable $e) {
            // Never hide failures from the test suite.
            if (app()->environment('testing')) {
                throw $e;
            }

            report($e);

            return $this->respond($proposal, null, 'Could not apply: '.$e->getMessage());
        }

        return $this->respond($proposal, $message, null);
    }

    protected function authorizeAdmin(): void
    {
        $ability = config('webcontent.gate');

        if ($ability) {
            Gate::authorize($ability, ContentProposal::class);
        }
    }

    /**
     * Signed-link clicks (from email/Telegram) get a small standalone
     * confirmation page; normal navigation is redirected back to the review
     * list when possible.
     */
    protected function respond(ContentProposal $proposal, ?string $success, ?string $error)
    {
        if (request()->wantsJson()) {
            return response()->json([
                'status' => $proposal->refresh()->status,
                'message' => $success ?? $error,
            ], $error ? 422 : 200);
        }

        // Only bounce back to the list when the click came from it (in-app).
        // Email/Telegram clicks (no referrer) get the standalone confirmation
        // page, since the visitor may not be logged in.
        $referrer = (string) request()->headers->get('referer');
        $fromReviewList = str_starts_with($referrer, route('webcontent.proposals.index'));

        if ($error === null && $fromReviewList) {
            return redirect()->route('webcontent.proposals.index')->with('success', $success);
        }

        return view('webcontent::proposals.decision', [
            'proposal' => $proposal,
            'message' => $success ?? $error,
            'ok' => $success !== null,
        ]);
    }
}
