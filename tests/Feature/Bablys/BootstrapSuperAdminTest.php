<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BootstrapSuperAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_bootstrap_requires_exact_confirmation_and_verified_existing_user(): void
    {
        $user = User::factory()->create(['email' => 'manager@bablys.test']);
        $this->artisan('bablys:bootstrap-super-admin', [
            'email' => $user->email,
            '--confirm-email' => 'different@bablys.test',
        ])->assertFailed();
        $this->assertDatabaseCount('cms_role_user', 0);

        $user->forceFill(['email_verified_at' => null])->save();
        $this->artisan('bablys:bootstrap-super-admin', [
            'email' => $user->email,
            '--confirm-email' => $user->email,
        ])->assertFailed();
        $this->assertDatabaseCount('cms_role_user', 0);
    }

    public function test_bootstrap_is_one_time_and_never_creates_new_users(): void
    {
        $user = User::factory()->create(['email' => 'owner@bablys.test']);
        $another = User::factory()->create(['email' => 'other@bablys.test']);

        $this->artisan('bablys:bootstrap-super-admin', [
            'email' => $user->email,
            '--confirm-email' => $user->email,
        ])->assertSuccessful();

        $role = CmsRole::query()->where('key', 'super_admin')->firstOrFail();
        $this->assertDatabaseHas('cms_role_user', [
            'role_id' => $role->getKey(), 'user_id' => $user->getKey(),
        ]);

        $this->artisan('bablys:bootstrap-super-admin', [
            'email' => $another->email,
            '--confirm-email' => $another->email,
        ])->assertFailed();
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('cms_role_user', 1);
    }

    public function test_unknown_email_does_not_create_account(): void
    {
        $this->artisan('bablys:bootstrap-super-admin', [
            'email' => 'missing@bablys.test',
            '--confirm-email' => 'missing@bablys.test',
        ])->assertFailed();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('cms_role_user', 0);
    }
}
