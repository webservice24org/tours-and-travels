<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('travel_booking_request_quote_versions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('booking_request_id')->constrained('travel_tour_booking_requests')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->char('fingerprint', 64);
            $table->string('currency', 3);
            $table->unsignedTinyInteger('fraction_digits');
            $table->unsignedBigInteger('regular_total_minor');
            $table->unsignedBigInteger('discount_minor');
            $table->unsignedBigInteger('total_minor');
            $table->json('price_lines_snapshot');
            $table->dateTime('quoted_at_utc');
            $table->dateTime('accepted_at_utc')->nullable();
            $table->string('accepted_via', 16)->nullable();
            $table->foreignId('accepted_by_customer_id')->nullable();

            $table->foreign(
                'accepted_by_customer_id',
                'bablys_quote_acceptor_fk'
            )->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->timestamps();
            $table->unique(['booking_request_id', 'revision'], 'travel_breq_quote_revision_unique');
            $table->index(['booking_request_id', 'accepted_at_utc'], 'travel_breq_quote_accepted_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_booking_request_quote_versions');
    }
};
