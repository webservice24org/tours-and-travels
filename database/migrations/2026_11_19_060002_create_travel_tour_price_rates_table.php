<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_tour_price_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('variant_id')->constrained('travel_tour_price_variants')->cascadeOnDelete();
            $table->string('traveler_type', 16);
            // Never store money in floating-point or rely on database decimal casts.
            $table->unsignedBigInteger('regular_minor');
            $table->unsignedBigInteger('sale_minor')->nullable();
            $table->timestamp('sale_starts_at')->nullable();
            $table->timestamp('sale_ends_at')->nullable();
            $table->timestamps();

            $table->unique(['variant_id', 'traveler_type'], 'travel_tour_price_rate_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_tour_price_rates');
    }
};
