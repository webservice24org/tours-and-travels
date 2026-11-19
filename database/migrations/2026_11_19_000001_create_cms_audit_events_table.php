<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_audit_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Retain the historical actor ID even if that user is subsequently deleted.
            // Intentionally not an FK with ON DELETE CASCADE / SET NULL.
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 100);
            $table->string('subject_type', 80);
            $table->string('subject_id', 64)->nullable();
            $table->json('metadata_json');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('occurred_at', 6);

            $table->index(['actor_id', 'occurred_at'], 'cms_audit_actor_time_idx');
            $table->index(['action', 'occurred_at'], 'cms_audit_action_time_idx');
            $table->index(['subject_type', 'subject_id', 'occurred_at'], 'cms_audit_subject_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_audit_events');
    }
};
