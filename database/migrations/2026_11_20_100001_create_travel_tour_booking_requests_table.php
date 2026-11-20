<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_tour_booking_requests', static function (Blueprint $table): void {
            $table->id();
            $table->string('reference_key', 48)->unique('travel_booking_request_ref_unique');
            $table->foreignId('tour_id')->constrained('travel_tours')->restrictOnDelete();
            $table->foreignId('departure_id')->constrained('travel_tour_departures')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->unique('travel_booking_request_booking_unique')
                ->constrained('travel_tour_bookings')->restrictOnDelete();
            $table->string('status', 32)->default('awaiting_verification');
            $table->string('variant_code', 60);
            $table->unsignedSmallInteger('adults');
            $table->unsignedSmallInteger('children')->default(0);
            // These fields are encrypted with the application's Laravel APP_KEY.
            $table->text('contact_name_encrypted');
            $table->text('contact_email_encrypted');
            $table->text('contact_phone_encrypted')->nullable();
            // Keyed, irreversible lookup tag; NOT an authentication credential.
            $table->char('contact_email_lookup_hmac', 64)->index('travel_booking_request_email_lookup');
            // One-time guest verification secret and separate withdrawal/access secret.
            $table->char('verification_token_hash', 64)->nullable();
            $table->char('access_token_hash', 64)->nullable();
            $table->dateTime('verification_expires_at_utc')->nullable();
            $table->dateTime('expires_at_utc');
            $table->dateTime('verified_at_utc')->nullable();
            $table->dateTime('withdrawn_at_utc')->nullable();
            $table->dateTime('converted_at_utc')->nullable();
            $table->timestamps();

            $table->index(['tour_id', 'status', 'id'], 'travel_booking_request_tour_status');
            $table->index(['customer_id', 'status', 'id'], 'travel_booking_request_customer_status');
            $table->index(['expires_at_utc', 'status'], 'travel_booking_request_expiry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_tour_booking_requests');
    }
};
