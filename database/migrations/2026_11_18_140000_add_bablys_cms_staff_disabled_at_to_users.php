<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This flag affects *CMS staff access only*, not normal customer login.
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('cms_staff_disabled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('cms_staff_disabled_at');
        });
    }
};
