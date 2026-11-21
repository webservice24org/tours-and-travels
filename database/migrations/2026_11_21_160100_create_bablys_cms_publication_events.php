<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bablys_site_settings', static function (Blueprint $table): void {
            $table->unsignedInteger('publication_serial')->default(0);
        });
        Schema::table('bablys_navigation_menus', static function (Blueprint $table): void {
            $table->unsignedInteger('publication_serial')->default(0);
        });
        Schema::create('bablys_cms_publication_events', static function (Blueprint $table): void {
            $table->id();
            $table->string('subject_type', 24); // settings | navigation
            $table->unsignedBigInteger('subject_id'); // site settings singleton ID, or menu ID
            $table->string('action', 16); // publish | rollback
            $table->unsignedInteger('publication_serial');
            $table->unsignedInteger('source_draft_version');
            $table->json('snapshot'); // immutable published payload, not arbitrary editor input
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('restored_event_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type','subject_id','id'], 'bablys_cms_event_subject_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bablys_cms_publication_events');
        Schema::table('bablys_navigation_menus', static function (Blueprint $table): void {
            $table->dropColumn('publication_serial');
        });
        Schema::table('bablys_site_settings', static function (Blueprint $table): void {
            $table->dropColumn('publication_serial');
        });
    }
};
