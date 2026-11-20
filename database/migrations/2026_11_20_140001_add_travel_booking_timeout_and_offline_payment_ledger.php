<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travel_tour_bookings', static function (Blueprint $table): void {
            $table->dateTime('confirmation_due_at_utc')->nullable();
            $table->dateTime('timeout_review_required_at_utc')->nullable();
            $table->dateTime('timeout_reviewed_at_utc')->nullable();
            $table->foreignId('timeout_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(
                ['status', 'payment_state', 'confirmation_due_at_utc', 'timeout_review_required_at_utc'],
                'travel_booking_timeout_due_idx'
            );
        });

        Schema::create('travel_tour_booking_offline_payments', static function (Blueprint $table): void {
            $table->id();
            // Exactly one full, externally verified offline payment per booking in this phase.
            $table->foreignId('booking_id')->unique('travel_booking_offline_once_unique')
                ->constrained('travel_tour_bookings')->restrictOnDelete();
            $table->string('evidence_reference_hash', 64)->unique('travel_booking_evidence_ref_unique');
            $table->string('currency', 3);
            $table->unsignedBigInteger('amount_minor');
            $table->string('method', 32)->default('offline_verified');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at_utc');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_tour_booking_offline_payments');
        Schema::table('travel_tour_bookings', static function (Blueprint $table): void {
            $table->dropIndex('travel_booking_timeout_due_idx');
            $table->dropConstrainedForeignId('timeout_reviewed_by');
            $table->dropColumn([
                'confirmation_due_at_utc', 'timeout_review_required_at_utc', 'timeout_reviewed_at_utc',
            ]);
        });
    }
};
