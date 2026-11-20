<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_tour_destination', function (Blueprint $table): void {
            $table->foreignId('tour_id')->constrained('travel_tours')->cascadeOnDelete();
            $table->foreignId('destination_id')->constrained('travel_destinations')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);

            $table->primary(['tour_id', 'destination_id'], 'travel_tour_destination_pk');
            $table->index(['destination_id', 'tour_id'], 'travel_tour_destination_reverse_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_tour_destination');
    }
};
