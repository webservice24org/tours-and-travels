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
            $table->char('submission_key_hash', 64)->nullable()->unique('travel_booking_request_submission_unique');
            $table->string('consent_policy_version', 32)->nullable();
            $table->dateTime('consent_accepted_at_utc')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('travel_tour_booking_requests', static function (Blueprint $table): void {
            $table->dropUnique('travel_booking_request_submission_unique');
            $table->dropColumn(['submission_key_hash', 'consent_policy_version', 'consent_accepted_at_utc']);
        });
    }
};
