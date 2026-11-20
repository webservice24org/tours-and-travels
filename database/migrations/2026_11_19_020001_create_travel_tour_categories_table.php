<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_tour_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()
                ->constrained('travel_tour_categories')->restrictOnDelete();
            $table->string('name', 180);
            $table->string('slug', 160)->unique();
            $table->string('summary', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['parent_id', 'status', 'sort_order'], 'travel_category_tree_visibility_idx');
            $table->index(['status', 'sort_order', 'id'], 'travel_category_public_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_tour_categories');
    }
};
