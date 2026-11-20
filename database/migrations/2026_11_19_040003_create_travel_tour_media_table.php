<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('travel_tour_media', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tour_id')->constrained('travel_tours')->cascadeOnDelete();
            $table->string('disk', 32)->default('public');
            $table->string('path', 255)->unique();
            $table->string('mime_type', 32);
            $table->unsignedInteger('file_size');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->string('alt_text', 200)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_featured')->default(false);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tour_id', 'sort_order', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_tour_media');
    }
};
