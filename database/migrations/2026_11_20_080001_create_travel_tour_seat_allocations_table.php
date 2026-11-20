<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_tour_seat_allocations', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tour_id')->constrained('travel_tours')->cascadeOnDelete();
            // A released allocation is kept as a tombstone even if its departure is later removed.
            $table->foreignId('departure_id')->nullable()->constrained('travel_tour_departures')->nullOnDelete();
            $table->string('departure_code', 60);
            // Opaque, non-PII idempotency key; globally unique across active tours.
            $table->string('reference_key', 100)->unique('travel_seat_allocation_reference_unique');
            $table->string('variant_code', 60);
            $table->unsignedSmallInteger('adults');
            $table->unsignedSmallInteger('children')->default(0);
            $table->unsignedSmallInteger('seats');
            $table->string('status', 16)->default('committed');
            $table->dateTime('committed_at');
            $table->dateTime('released_at')->nullable();
            $table->timestamps();

            $table->index(['tour_id', 'status', 'id'], 'travel_seat_tour_status_idx');
            $table->index(['departure_id', 'status'], 'travel_seat_departure_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_tour_seat_allocations');
    }
};
