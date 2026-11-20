<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_tour_price_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tour_id')->constrained('travel_tours')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('name', 120);
            $table->char('currency', 3);
            $table->string('pricing_model', 16);
            $table->unsignedSmallInteger('min_travelers')->default(1);
            $table->unsignedSmallInteger('max_travelers')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['tour_id', 'code'], 'travel_tour_price_variant_code_unique');
            $table->index(['tour_id', 'is_active', 'sort_order', 'id'], 'travel_tour_price_variant_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_tour_price_variants');
    }
};
