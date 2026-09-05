<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proposed changes produced by the AI content agent.
 *
 * The agent NEVER mutates web_contents directly: every suggestion (add new
 * page / update existing page / remove obsolete page) lands here as a pending
 * proposal, and the owner approves or discards it via the emailed/Telegram
 * signed links or the review page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webcontent_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('web_content_id')->nullable()->constrained('web_contents')->nullOnDelete();
            $table->string('action');              // add | update | remove
            $table->string('slug')->index();
            $table->string('title')->nullable();
            $table->text('rationale');
            $table->json('proposed')->nullable();  // replacement/creation fields
            $table->json('sources')->nullable();   // research references
            $table->decimal('confidence', 3, 2)->nullable();
            $table->string('status')->default('pending')->index(); // pending | applied | rejected
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webcontent_proposals');
    }
};
