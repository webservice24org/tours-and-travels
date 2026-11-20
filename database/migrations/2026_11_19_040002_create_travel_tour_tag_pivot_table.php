<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('travel_tour_tag', static function (Blueprint $table): void {
            $table->foreignId('tour_id')->constrained('travel_tours')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('travel_tour_tags')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->primary(['tour_id', 'tag_id']);
            $table->index(['tag_id', 'tour_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('travel_tour_tag');
    }
};
