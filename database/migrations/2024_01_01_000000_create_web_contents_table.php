<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the `web_contents` table with the FULL consolidated schema.
 *
 * This merges the incremental migrations the package was extracted from
 * (base table + head_meta + locale + audit/soft-delete columns) plus the
 * columns that previously existed only in the production database
 * (style, script, attach_form_id, is_web_page).
 *
 * Two kinds of rows live in the table:
 *  - is_web_page = true  : servable pages
 *  - is_web_page = false : form fragments attachable to a page via attach_form_id
 * (Slug is globally unique, as in the source system — enforced again by the
 * PageController update validation.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_contents', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('locale', 12)->nullable();
            $table->string('title');
            $table->json('head_meta')->nullable();
            $table->longText('content');
            $table->longText('style')->nullable();
            $table->longText('script')->nullable();
            $table->unsignedBigInteger('attach_form_id')->nullable();
            $table->boolean('is_web_page')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('attach_form_id')
                ->references('id')->on('web_contents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_contents');
    }
};
