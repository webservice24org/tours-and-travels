<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_tour_departures', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tour_id')->constrained('travel_tours')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('name', 120);
            $table->string('timezone_name', 64);
            // UTC values stored as naive SQL DATETIME to avoid connection timezone conversions.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->dateTime('booking_closes_at')->nullable();
            $table->unsignedSmallInteger('capacity_total');
            // Future booking module alone may increment/decrement this under parent-first row locks.
            $table->unsignedSmallInteger('seats_committed')->default(0);
            $table->string('status', 16)->default('draft');
            $table->string('meeting_point', 180)->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamps();

            $table->unique(['tour_id', 'code'], 'travel_departure_tour_code_unique');
            $table->index(['tour_id', 'starts_at', 'id'], 'travel_departure_tour_schedule_idx');
            $table->index(['status', 'starts_at'], 'travel_departure_status_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_tour_departures');
    }
};
