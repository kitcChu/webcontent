<?php

namespace Kit\WebContent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single change the AI agent proposes for web_contents. Applied ONLY after
 * explicit owner approval (email/Telegram signed link or review page).
 */
class ContentProposal extends Model
{
    protected $table = 'webcontent_proposals';

    /**
     * Fresh instances (ContentProposal::create([...])) carry the pending
     * status even before the row is reloaded, so apply()/reject() guards
     * behave consistently.
     */
    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    public const ACTION_ADD = 'add';
    public const ACTION_UPDATE = 'update';
    public const ACTION_REMOVE = 'remove';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'web_content_id',
        'action',
        'slug',
        'title',
        'rationale',
        'proposed',
        'sources',
        'confidence',
        'status',
        'applied_at',
    ];

    protected $casts = [
        'proposed' => 'array',
        'sources' => 'array',
        'applied_at' => 'datetime',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(WebContent::class, 'web_content_id');
    }

    /**
     * The page this proposal targets (by FK, falling back to the slug).
     */
    public function targetPage(): ?WebContent
    {
        if ($this->web_content_id) {
            return WebContent::query()->find($this->web_content_id);
        }

        return WebContent::query()->where('slug', $this->slug)->first();
    }

    /**
     * Apply the proposal to web_contents. Only allowed once, while pending.
     * Removals soft-delete the target page so they are always reversible.
     */
    public function apply(): void
    {
        $this->assertPending();

        $fields = $this->applicableFields();

        if ($this->action === self::ACTION_ADD) {
            $page = WebContent::query()->create($fields + [
                'slug' => $this->slug,
                'is_web_page' => true,
            ]);
        } else {
            $page = $this->targetPage();

            if (!$page) {
                throw new \RuntimeException(
                    "The target page [{$this->slug}] no longer exists; nothing to apply."
                );
            }

            if ($this->action === self::ACTION_REMOVE) {
                $page->delete(); // soft delete — reversible
            } else {
                $page->update($fields);
            }
        }

        $this->forceFill([
            'status' => self::STATUS_APPLIED,
            'applied_at' => now(),
            'web_content_id' => $this->web_content_id ?? $page->id ?? null,
        ])->save();
    }

    /**
     * Reject the proposal without touching web_contents.
     */
    public function reject(): void
    {
        $this->assertPending();

        $this->forceFill(['status' => self::STATUS_REJECTED])->save();
    }

    /**
     * Fields the AI is allowed to write into web_contents. Null values are
     * dropped so an update only overwrites what the agent actually proposes.
     */
    protected function applicableFields(): array
    {
        $allowed = collect($this->proposed ?? [])
            ->only(['title', 'content', 'style', 'script', 'head_meta', 'locale'])
            ->filter(fn ($value) => $value !== null)
            ->all();

        if ($this->action === self::ACTION_UPDATE && !isset($allowed['title'])) {
            // Title column is NOT NULL: keep the current one unless proposed.
            $allowed['title'] = $this->targetPage()?->title ?? $this->title ?? $this->slug;
        }

        return $allowed;
    }

    protected function assertPending(): void
    {
        if ($this->status !== self::STATUS_PENDING) {
            throw new \LogicException("Proposal #{$this->id} is already {$this->status}.");
        }
    }
}
