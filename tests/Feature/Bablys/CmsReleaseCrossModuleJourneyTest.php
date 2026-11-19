<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** P2B.6C: actual HTTP mutation -> database -> Gate -> audit continuity. */
final class CmsReleaseCrossModuleJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_public_home_and_authenticated_customer_dashboard_remain_available(): void
    {
        $this->get('/')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('public/home'));
        $this->get('/login')->assertOk();
        $this->get('/register')->assertOk();
        $this->get('/dashboard')->assertRedirect('/login');

        $customer = User::factory()->create();
        $this->actingAs($customer)->get('/dashboard')->assertOk();
        $this->actingAs($customer)->get('/admin')->assertForbidden();
    }

    public function test_custom_role_creation_assignment_revocation_and_audit_feed_form_one_chain(): void
    {
        $owner = $this->withRole('super_admin');
        $staff = $this->withRole('booking_agent');

        $created = $this->actingAs($owner)->postJson('/admin/internal/cms-roles', [
            'key' => 'custom_release_gallery',
            'name' => 'Release Gallery Team',
            'description' => 'Regression journey',
            'permission_keys' => ['galleries.edit'],
        ])->assertCreated();
        $roleId = (int) $created->json('data.id');

        $this->actingAs($owner)->putJson('/admin/internal/staff-users/'.$staff->getKey().'/roles', [
            'role_keys' => ['custom_release_gallery'],
        ])->assertOk()->assertJsonPath('data.role_keys', ['custom_release_gallery']);
        self::assertTrue(Gate::forUser($staff)->allows('galleries.edit'));
        self::assertFalse(Gate::forUser($staff)->allows('roles.manage'));

        $this->actingAs($owner)->putJson('/admin/internal/cms-roles/'.$roleId.'/permissions', [
            'permission_keys' => ['tours.view'],
        ])->assertOk();
        self::assertFalse(Gate::forUser($staff)->allows('galleries.edit'));
        self::assertTrue(Gate::forUser($staff)->allows('tours.view'));

        foreach (['role.created', 'staff.roles.changed', 'role.permissions.changed'] as $event) {
            $this->assertDatabaseHas('cms_audit_events', ['actor_id' => $owner->getKey(), 'action' => $event]);
        }
        $this->actingAs($owner)
            ->getJson('/admin/internal/audit-logs?action=role.permissions.changed')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject_id', (string) $roleId);
    }

    public function test_staff_suspension_and_restoration_affect_gates_and_emit_exactly_one_event_each(): void
    {
        $owner = $this->withRole('super_admin');
        $staff = $this->withRole('booking_agent');
        self::assertTrue(Gate::forUser($staff)->allows('bookings.view'));

        $endpoint = '/admin/internal/staff-users/'.$staff->getKey().'/access';
        $this->actingAs($owner)->patchJson($endpoint, ['enabled' => false])
            ->assertOk()->assertJsonPath('data.cms_staff_enabled', false);
        self::assertFalse(Gate::forUser($staff)->allows('bookings.view'));
        $this->actingAs($staff)->get('/admin')->assertForbidden();

        $this->actingAs($owner)->patchJson($endpoint, ['enabled' => false])->assertOk();
        $this->actingAs($owner)->patchJson($endpoint, ['enabled' => true])
            ->assertOk()->assertJsonPath('data.cms_staff_enabled', true);
        self::assertTrue(Gate::forUser($staff)->allows('bookings.view'));

        self::assertSame(1, (int) DB::table('cms_audit_events')->where('action', 'staff.suspended')->count());
        self::assertSame(1, (int) DB::table('cms_audit_events')->where('action', 'staff.restored')->count());
    }

    public function test_staff_setup_link_is_private_and_never_verifies_an_account(): void
    {
        Notification::fake();
        $owner = $this->withRole('super_admin');

        $this->actingAs($owner)->postJson('/admin/internal/staff-users', [
            'name' => 'Release Invitee',
            'email' => 'release.invitee@bablys.test',
            'role_keys' => ['content_editor'],
        ])->assertCreated()->assertJsonMissingPath('data.password');

        $invitee = User::query()->where('email', 'release.invitee@bablys.test')->firstOrFail();
        $this->actingAs($owner)->postJson('/admin/internal/staff-users/'.$invitee->getKey().'/setup-link')
            ->assertOk()->assertJsonMissingPath('token')->assertJsonMissingPath('password');
        Notification::assertSentTo($invitee, ResetPassword::class);
        self::assertNull($invitee->refresh()->email_verified_at);
        self::assertFalse(Gate::forUser($invitee)->allows('blog.edit'));
        $this->actingAs($invitee)->get('/admin')->assertForbidden();
        $this->assertDatabaseHas('cms_audit_events', [
            'actor_id' => $owner->getKey(), 'action' => 'staff.created', 'subject_id' => (string) $invitee->getKey(),
        ]);
    }

    public function test_invalid_role_assignment_does_not_mutate_database_or_emit_change_event(): void
    {
        $owner = $this->withRole('super_admin');
        $staff = $this->withRole('booking_agent');
        $endpoint = '/admin/internal/staff-users/'.$staff->getKey().'/roles';

        $this->actingAs($owner)->putJson($endpoint, ['role_keys' => ['super_admin']])
            ->assertUnprocessable();
        $this->actingAs($owner)->putJson($endpoint, ['role_keys' => ['missing_custom_role']])
            ->assertUnprocessable();

        $role = CmsRole::query()->where('key', 'booking_agent')->firstOrFail();
        $this->assertDatabaseHas('cms_role_user', ['user_id' => $staff->getKey(), 'role_id' => $role->getKey()]);
        self::assertSame(0, (int) DB::table('cms_audit_events')->where('action', 'staff.roles.changed')->count());
        self::assertTrue(Gate::forUser($staff)->allows('bookings.view'));
    }

    private function withRole(string $key): User
    {
        $user = User::factory()->create();
        $role = CmsRole::query()->where('key', $key)->firstOrFail();
        $role->users()->attach($user->getKey());

        return $user;
    }
}
