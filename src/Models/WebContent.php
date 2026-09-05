<?php

namespace Kit\WebContent\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Kit\WebContent\Traits\HasAuditFields;

/**
 * A CMS-managed web page (or form fragment) stored in the `web_contents` table.
 *
 * Two kinds of rows live in the table:
 *  - is_web_page = true  : servable pages (public catch-all route + admin editor)
 *  - is_web_page = false : form-fragment rows that can be attached to a page
 *                          via `attach_form_id` and rendered by the page UI.
 */
class WebContent extends Model
{
    use HasFactory, SoftDeletes, HasAuditFields;

    protected $fillable = [
        'slug',
        'locale',
        'title',
        'content',
        'style',
        'script',
        'attach_form_id',
        'head_meta',
        'is_web_page',
        'created_at',
        'updated_at',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'is_web_page' => 'boolean',
        'head_meta' => 'array',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
        'is_web_page',
        'attach_form_id',
    ];

    public function attachedForm()
    {
        return $this->belongsTo(WebContent::class, 'attach_form_id', 'id');
    }

    /**
     * Scope to content pages only (excludes form-fragment rows).
     */
    public function scopeWebPage($query)
    {
        return $query->where('is_web_page', true);
    }
}
