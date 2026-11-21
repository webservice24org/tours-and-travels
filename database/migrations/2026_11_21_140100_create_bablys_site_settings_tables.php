<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bablys_site_settings', static function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 32)->unique();
            $table->json('draft_payload')->nullable();
            $table->json('published_payload')->nullable();
            $table->unsignedInteger('draft_version')->default(0);
            $table->unsignedInteger('published_version')->default(0);
            $table->foreignId('draft_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
        Schema::create('bablys_site_settings_revisions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_settings_id')->constrained('bablys_site_settings')->cascadeOnDelete();
            $table->unsignedInteger('draft_version');
            $table->json('draft_payload');
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['site_settings_id', 'draft_version'], 'bablys_settings_revision_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bablys_site_settings_revisions');
        Schema::dropIfExists('bablys_site_settings');
    }
};
