<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('travel_destinations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 180);
            $table->string('slug', 160)->unique();
            $table->char('country_code', 2);
            $table->string('region_name', 160)->nullable();
            $table->string('city_name', 160)->nullable();
            $table->string('summary', 500)->nullable();
            $table->text('description')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('status', 24)->default('draft');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['country_code', 'status', 'sort_order'], 'travel_destination_country_visibility_idx');
            $table->index(['status', 'sort_order', 'id'], 'travel_destination_public_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_destinations');
    }
};
