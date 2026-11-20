<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_tour_category', function (Blueprint $table): void {
            $table->foreignId('tour_id')->constrained('travel_tours')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('travel_tour_categories')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);

            $table->primary(['tour_id', 'category_id'], 'travel_tour_category_pk');
            $table->index(['category_id', 'tour_id'], 'travel_tour_category_reverse_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_tour_category');
    }
};
