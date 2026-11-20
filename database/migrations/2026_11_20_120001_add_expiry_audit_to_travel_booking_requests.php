<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('travel_tour_booking_requests', static function (Blueprint $table): void {
            $table->dateTime('expired_at_utc')->nullable();
            $table->string('expired_reason', 32)->nullable();
            $table->index(['status', 'verification_expires_at_utc', 'id'], 'travel_booking_request_verify_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::table('travel_tour_booking_requests', static function (Blueprint $table): void {
            $table->dropIndex('travel_booking_request_verify_expiry_idx');
            $table->dropColumn(['expired_at_utc', 'expired_reason']);
        });
    }
};
