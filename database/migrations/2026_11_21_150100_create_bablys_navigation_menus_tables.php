<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bablys_navigation_menus', static function (Blueprint $table): void {
            $table->id();
            $table->string('menu_key', 48)->unique();
            $table->string('draft_name', 100);
            $table->string('published_name', 100)->nullable();
            $table->json('draft_items');
            $table->json('published_items')->nullable();
            $table->unsignedInteger('draft_version')->default(0);
            $table->unsignedInteger('published_version')->default(0);
            $table->foreignId('draft_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('bablys_navigation_menu_revisions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('navigation_menu_id')->constrained('bablys_navigation_menus')->cascadeOnDelete();
            $table->unsignedInteger('draft_version');
            $table->string('draft_name', 100);
            $table->json('draft_items');
            $table->foreignId('edited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['navigation_menu_id', 'draft_version'], 'bablys_navigation_revision_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bablys_navigation_menu_revisions');
        Schema::dropIfExists('bablys_navigation_menus');
    }
};
