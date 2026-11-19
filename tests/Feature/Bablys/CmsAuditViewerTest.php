<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Cms\Audit\CmsAuditRecorder;
use App\Models\Cms\CmsPermission;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** P2B.5C: read-only, owner-only audit feed and React Inertia screen. */
final class CmsAuditViewerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_guest_customer_and_editor_cannot_view_audit_page_or_api(): void
    {
        $this->get('/admin/audit-logs')->assertRedirect('/login');
        $this->getJson('/admin/internal/audit-logs')->assertUnauthorized();

        $customer = User::factory()->create();
        $this->actingAs($customer)->get('/admin/audit-logs')->assertForbidden();
        $this->actingAs($customer)->getJson('/admin/internal/audit-logs/options')->assertForbidden();

        $editor = $this->withRole('content_editor');
        $this->actingAs($editor)->get('/admin/audit-logs')->assertForbidden();
        $this->actingAs($editor)->getJson('/admin/internal/audit-logs')->assertForbidden();
    }

    public function test_super_admin_can_open_react_audit_viewer(): void
    {
        $this->actingAs($this->withRole('super_admin'))->get('/admin/audit-logs')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('admin/audit-logs/index'));
    }

    public function test_accidental_audit_view_grant_to_editor_does_not_make_an_owner(): void
    {
        $editor = $this->withRole('content_editor');
        $role = CmsRole::query()->where('key', 'content_editor')->firstOrFail();
        $permission = CmsPermission::query()->where('key', 'audit.view')->firstOrFail();
        $role->permissions()->syncWithoutDetaching([$permission->getKey()]);

        $this->actingAs($editor)->get('/admin/audit-logs')->assertForbidden();
        $this->actingAs($editor)->getJson('/admin/internal/audit-logs')->assertForbidden();
    }

    public function test_listing_is_paginated_and_does_not_expose_metadata_or_ip(): void
    {
        $owner = $this->withRole('super_admin');
        for ($i = 1; $i <= 12; $i++) {
            $this->record($owner, 'staff.created', 'staff_user', $i, [
                'role_keys_after' => ['content_editor'], 'password' => 'never-visible',
            ]);
        }

        $this->actingAs($owner)->getJson('/admin/internal/audit-logs?per_page=10&page=1')
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 12)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonMissingPath('data.0.metadata')
            ->assertJsonMissingPath('data.0.ip_address')
            ->assertDontSee('never-visible');

        $this->actingAs($owner)->getJson('/admin/internal/audit-logs?per_page=10&page=2')
            ->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_filtering_by_action_actor_subject_and_text_search(): void
    {
        $owner = $this->withRole('super_admin');
        $secondActor = $this->withRole('super_admin');
        $this->record($owner, 'staff.suspended', 'staff_user', 25, ['enabled_after' => false]);
        $this->record($secondActor, 'role.created', 'cms_role', 30, ['role_key' => 'custom_tour_agent']);

        $this->actingAs($owner)->getJson('/admin/internal/audit-logs?action=staff.suspended')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject_id', '25');
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs?actor_id='.$secondActor->id)
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'role.created');
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs?subject_type=cms_role&subject_id=30')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs?search=role.created')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_date_range_filters_in_utc_including_end_date(): void
    {
        $owner = $this->withRole('super_admin');
        $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'UTC'));
        $this->record($owner, 'staff.suspended', 'staff_user', 31);
        $this->travelTo(CarbonImmutable::parse('2026-11-19 22:00:00', 'UTC'));
        $this->record($owner, 'staff.restored', 'staff_user', 31);

        $this->actingAs($owner)->getJson('/admin/internal/audit-logs?date_from=2026-11-19&date_to=2026-11-19')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'staff.restored');
        $this->travelBack();
    }

    public function test_event_detail_is_read_only_and_redacts_unknown_metadata_fields(): void
    {
        $owner = $this->withRole('super_admin');
        $id = $this->record($owner, 'staff.roles.changed', 'staff_user', 28, [
            'role_keys_before' => ['content_editor'],
            'role_keys_after' => ['booking_agent'],
            'password' => 'secret-password',
            'employee_email' => 'private@bablys.test',
        ]);

        $this->actingAs($owner)->getJson('/admin/internal/audit-logs/'.$id)
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.metadata.role_keys_after.0', 'booking_agent')
            ->assertJsonMissingPath('data.metadata.password')
            ->assertJsonMissingPath('data.metadata.employee_email')
            ->assertDontSee('secret-password')
            ->assertDontSee('private@bablys.test');
    }

    public function test_missing_and_deleted_actors_use_historical_identity_fallbacks(): void
    {
        $owner = $this->withRole('super_admin');
        $deletedActor = $this->withRole('content_editor');
        $actorId = (int) $deletedActor->id;
        $this->record($deletedActor, 'staff.created', 'staff_user', 33);
        $deletedActor->delete();

        $this->actingAs($owner)->getJson('/admin/internal/audit-logs')
            ->assertOk()->assertJsonPath('data.0.actor_id', $actorId)
            ->assertJsonPath('data.0.actor_name', 'Deleted account #'.$actorId);
    }

    public function test_filter_validation_blocks_invalid_ranges_and_pagination(): void
    {
        $owner = $this->withRole('super_admin');
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs?per_page=1000')
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs?page=-2')
            ->assertUnprocessable()->assertJsonValidationErrors('page');
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs?date_from=2026-11-20&date_to=2026-11-19')
            ->assertUnprocessable()->assertJsonValidationErrors('date_to');
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs?action=unsafe;drop')
            ->assertUnprocessable()->assertJsonValidationErrors('action');
    }

    public function test_options_reflect_actual_event_types_but_never_include_metadata(): void
    {
        $owner = $this->withRole('super_admin');
        $this->record($owner, 'staff.created', 'staff_user', 12);
        $this->record($owner, 'role.created', 'cms_role', 13);
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs/options')
            ->assertOk()->assertJsonPath('data.actions', ['role.created', 'staff.created'])
            ->assertJsonPath('data.subject_types', ['cms_role', 'staff_user']);
    }

    public function test_non_owner_cannot_read_detail_and_there_are_no_write_endpoints(): void
    {
        $owner = $this->withRole('super_admin');
        $id = $this->record($owner, 'role.created', 'cms_role', 123);
        $editor = $this->withRole('content_editor');

        $this->actingAs($editor)->getJson('/admin/internal/audit-logs/'.$id)->assertForbidden();
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs/not-a-uuid')->assertNotFound();
        $this->actingAs($owner)->postJson('/admin/internal/audit-logs', [])->assertStatus(405);
        $this->actingAs($owner)->putJson('/admin/internal/audit-logs/'.$id, [])->assertStatus(405);
        $this->actingAs($owner)->deleteJson('/admin/internal/audit-logs/'.$id)->assertStatus(405);
        $this->assertDatabaseHas('cms_audit_events', ['id' => $id]);
    }

    public function test_real_role_mutation_appears_in_viewer_without_fresh_audit_write(): void
    {
        $owner = $this->withRole('super_admin');
        $this->actingAs($owner)->postJson('/admin/internal/cms-roles', [
            'key' => 'custom_audit_qa', 'name' => 'Audit QA', 'description' => null,
            'permission_keys' => ['tours.view'],
        ])->assertCreated();

        $this->actingAs($owner)->getJson('/admin/internal/audit-logs?action=role.created')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'role.created');
    }

    /** @param array<string, mixed> $metadata */
    private function record(
        ?User $actor,
        string $action,
        string $subjectType,
        int $subjectId,
        array $metadata = [],
    ): string {
        return app(CmsAuditRecorder::class)->record($action, $actor, $subjectType, $subjectId, $metadata);
    }

    private function withRole(string $key): User
    {
        $user = User::factory()->create();
        $role = CmsRole::query()->where('key', $key)->firstOrFail();
        $role->users()->attach($user->getKey());
        return $user;
    }
}
