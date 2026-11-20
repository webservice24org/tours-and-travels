<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_tours', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 200);
            $table->string('slug', 160)->unique();
            $table->string('summary', 500)->nullable();
            $table->longText('description')->nullable();
            $table->string('status', 24)->default('draft');
            $table->unsignedSmallInteger('duration_days')->default(1);
            $table->unsignedSmallInteger('duration_nights')->nullable();
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at', 'sort_order', 'id'], 'travel_tours_public_lookup_idx');
            $table->index(['is_featured', 'status', 'sort_order'], 'travel_tours_featured_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_tours');
    }
};
