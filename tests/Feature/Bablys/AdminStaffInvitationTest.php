<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class AdminStaffInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_guest_customer_and_non_owner_cannot_send_staff_setup_link(): void
    {
        $target = $this->pendingStaff('content_editor');
        $url = '/admin/internal/staff-users/'.$target->id.'/setup-link';

        $this->postJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson($url)->assertForbidden();
        $this->actingAs($this->withRole('booking_agent'))->postJson($url)->assertForbidden();
    }

    public function test_owner_can_send_one_time_reset_link_to_pending_staff_without_exposing_secrets(): void
    {
        Notification::fake();
        $owner = $this->withRole('super_admin');
        $target = $this->pendingStaff('content_editor');

        $response = $this->actingAs($owner)
            ->postJson('/admin/internal/staff-users/'.$target->id.'/setup-link');

        $response->assertOk()
            ->assertJsonMissingPath('token')
            ->assertJsonMissingPath('password');
        Notification::assertSentTo($target, ResetPassword::class);
        $this->assertNull($target->refresh()->email_verified_at);
    }

    public function test_super_admin_and_customer_targets_cannot_receive_setup_link(): void
    {
        Notification::fake();
        $owner = $this->withRole('super_admin');
        $otherOwner = $this->withRole('super_admin');
        $customer = User::factory()->create(['email_verified_at' => null]);

        $this->actingAs($owner)
            ->postJson('/admin/internal/staff-users/'.$otherOwner->id.'/setup-link')
            ->assertUnprocessable();
        $this->actingAs($owner)
            ->postJson('/admin/internal/staff-users/'.$customer->id.'/setup-link')
            ->assertUnprocessable();
        Notification::assertNothingSent();
    }

    public function test_suspended_or_already_verified_staff_cannot_receive_setup_link(): void
    {
        Notification::fake();
        $owner = $this->withRole('super_admin');
        $suspended = $this->pendingStaff('designer');
        $suspended->forceFill(['cms_staff_disabled_at' => now()])->save();
        $verified = $this->withRole('booking_agent');

        $this->actingAs($owner)
            ->postJson('/admin/internal/staff-users/'.$suspended->id.'/setup-link')
            ->assertUnprocessable();
        $this->actingAs($owner)
            ->postJson('/admin/internal/staff-users/'.$verified->id.'/setup-link')
            ->assertUnprocessable();
        Notification::assertNothingSent();
    }

    private function withRole(string $key): User
    {
        $user = User::factory()->create();
        $role = CmsRole::query()->where('key', $key)->firstOrFail();
        $role->users()->attach($user->getKey());

        return $user;
    }

    private function pendingStaff(string $key): User
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $role = CmsRole::query()->where('key', $key)->firstOrFail();
        $role->users()->attach($user->getKey());

        return $user;
    }
}
