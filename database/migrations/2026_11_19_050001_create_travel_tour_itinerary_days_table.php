<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_tour_itinerary_days', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tour_id')->constrained('travel_tours')->cascadeOnDelete();
            $table->unsignedSmallInteger('day_number');
            $table->string('title', 180);
            $table->string('location_label', 160)->nullable();
            $table->longText('description')->nullable();
            $table->string('overnight_location', 160)->nullable();
            $table->json('meals');
            $table->json('highlights');
            $table->timestamps();
            $table->unique(['tour_id', 'day_number'], 'travel_itinerary_tour_day_unique');
            $table->index(['tour_id', 'id'], 'travel_itinerary_tour_lookup_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_tour_itinerary_days');
    }
};
