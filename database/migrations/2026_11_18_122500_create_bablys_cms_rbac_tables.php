<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive only. Never modify the existing users table or auth data.
        Schema::create('cms_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('cms_permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 120)->unique();
            $table->string('module', 80);
            $table->string('action', 80);
            $table->string('scope', 32)->default('admin');
            $table->string('label', 160);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index(['module', 'action'], 'cms_permissions_module_action_idx');
        });

        Schema::create('cms_role_user', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained('cms_roles')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['role_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('cms_permission_role', function (Blueprint $table): void {
            $table->foreignId('permission_id')->constrained('cms_permissions')->restrictOnDelete();
            $table->foreignId('role_id')->constrained('cms_roles')->restrictOnDelete();
            $table->timestamps();
            $table->primary(['permission_id', 'role_id']);
            $table->index('role_id');
        });
    }

    public function down(): void
    {
        // Only reverse Bablys RBAC tables. No user/auth content is dropped.
        Schema::dropIfExists('cms_permission_role');
        Schema::dropIfExists('cms_role_user');
        Schema::dropIfExists('cms_permissions');
        Schema::dropIfExists('cms_roles');
    }
};
