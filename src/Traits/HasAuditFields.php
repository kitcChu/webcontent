<?php

namespace Kit\WebContent\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adds created_by / updated_by audit columns to a model and fills them
 * automatically from the authenticated user on create and update.
 *
 * Requires the table to have nullable `created_by` and `updated_by` columns.
 * When no user is authenticated (console commands, queues, webhooks) the
 * columns stay null.
 *
 * The related user model is configurable via `webcontent.user_model`
 * (defaults to App\Models\User) so the trait works in any host application.
 */
trait HasAuditFields
{
    public static function bootHasAuditFields(): void
    {
        static::creating(function ($model) {
            $model->created_by = auth()->id();
            $model->updated_by = auth()->id();
        });

        static::updating(function ($model) {
            $model->updated_by = auth()->id();
        });
    }

    /**
     * Fully-qualified class name of the user model (configurable per host app).
     */
    public static function auditUserModel(): string
    {
        return config('webcontent.user_model', 'App\\Models\\User');
    }

    /**
     * The user who created this record.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(static::auditUserModel(), 'created_by');
    }

    /**
     * The user who last updated this record.
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(static::auditUserModel(), 'updated_by');
    }
}
