<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_tour_bookings', static function (Blueprint $table): void {
            $table->id();
            // Historic bookings must prevent permanent deletion of their parent tour.
            $table->foreignId('tour_id')->constrained('travel_tours')->restrictOnDelete();
            // Cancelled departures may eventually be removed, but history remains.
            $table->foreignId('departure_id')->nullable()->constrained('travel_tour_departures')->nullOnDelete();
            $table->foreignId('seat_allocation_id')->unique('travel_booking_allocation_unique')
                ->constrained('travel_tour_seat_allocations')->restrictOnDelete();
            $table->string('reference_key', 100)->unique('travel_booking_reference_unique');
            $table->string('status', 24)->default('pending_confirmation');
            $table->string('payment_state', 24)->default('not_collected');
            $table->string('tour_title_snapshot', 255);
            $table->string('departure_code_snapshot', 60);
            $table->dateTime('departure_starts_at_utc');
            $table->string('departure_timezone', 64);
            $table->string('variant_code', 60);
            $table->string('currency', 3);
            $table->unsignedTinyInteger('fraction_digits');
            $table->unsignedSmallInteger('adults');
            $table->unsignedSmallInteger('children')->default(0);
            $table->unsignedSmallInteger('seats');
            $table->unsignedBigInteger('regular_total_minor');
            $table->unsignedBigInteger('discount_minor');
            $table->unsignedBigInteger('total_minor');
            $table->json('price_lines_snapshot');
            $table->dateTime('quoted_at_utc');
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tour_id', 'status', 'id'], 'travel_booking_tour_status_idx');
            $table->index(['departure_id', 'status'], 'travel_booking_departure_status_idx');
            $table->index(['created_at', 'id'], 'travel_booking_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_tour_bookings');
    }
};
