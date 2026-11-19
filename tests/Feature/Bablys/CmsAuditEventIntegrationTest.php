<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** P2B.5B — event-level tests of existing owner-only HTTP mutations. */
final class CmsAuditEventIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_staff_creation_logs_actor_and_roles_without_exposing_credentials_or_email(): void
    {
        $owner = $this->withRole('super_admin');
        $this->actingAs($owner)->postJson('/admin/internal/staff-users', [
            'name' => 'New Editor', 'email' => 'audit.editor@bablys.test',
            'role_keys' => ['content_editor'],
        ])->assertCreated();

        $staff = User::query()->where('email', 'audit.editor@bablys.test')->firstOrFail();
        $row = $this->singleEvent('staff.created', (string) $staff->getKey());
        self::assertSame((int) $owner->getKey(), (int) $row->actor_id);
        self::assertSame('staff_user', $row->subject_type);
        self::assertSame(['content_editor'], $this->metadata($row)['role_keys_after']);
        self::assertSame(false, $this->metadata($row)['email_verified']);
        self::assertStringNotContainsString('audit.editor@bablys.test', (string) $row->metadata_json);
        self::assertStringNotContainsString((string) $staff->password, (string) $row->metadata_json);
    }

    public function test_staff_profile_changes_log_only_changed_fields_not_sensitive_values(): void
    {
        $owner = $this->withRole('super_admin');
        $staff = $this->withRole('content_editor');

        $this->actingAs($owner)->putJson('/admin/internal/staff-users/'.$staff->id, [
            'name' => $staff->name, 'email' => $staff->email,
        ])->assertOk();
        self::assertSame(0, $this->eventCount('staff.updated'));

        $this->actingAs($owner)->putJson('/admin/internal/staff-users/'.$staff->id, [
            'name' => 'Changed Name', 'email' => 'different@bablys.test',
        ])->assertOk();
        $row = $this->singleEvent('staff.updated', (string) $staff->id);
        self::assertSame(['name', 'email'], $this->metadata($row)['changed_fields']);
        self::assertTrue($this->metadata($row)['email_verification_cleared']);
        self::assertStringNotContainsString('different@bablys.test', (string) $row->metadata_json);
        self::assertStringNotContainsString('Changed Name', (string) $row->metadata_json);
    }

    public function test_staff_role_replacement_records_before_after_and_skips_noop(): void
    {
        $owner = $this->withRole('super_admin');
        $staff = $this->withRole('booking_agent');
        $endpoint = '/admin/internal/staff-users/'.$staff->id.'/roles';
        $this->actingAs($owner)->putJson($endpoint, ['role_keys' => ['booking_agent']])->assertOk();
        self::assertSame(0, $this->eventCount('staff.roles.changed'));

        $this->actingAs($owner)->putJson($endpoint, ['role_keys' => ['content_editor']])->assertOk();
        $row = $this->singleEvent('staff.roles.changed', (string) $staff->id);
        self::assertSame(['booking_agent'], $this->metadata($row)['role_keys_before']);
        self::assertSame(['content_editor'], $this->metadata($row)['role_keys_after']);

        $this->actingAs($owner)->putJson($endpoint, ['role_keys' => []])->assertOk();
        self::assertSame(2, $this->eventCount('staff.roles.changed'));
        $last = DB::table('cms_audit_events')->where('action', 'staff.roles.changed')
            ->orderByDesc('occurred_at')->orderByDesc('id')->get();
        $payloads = $last->map(fn (object $event): array => $this->metadata($event))->all();
        self::assertContains(['role_keys_before' => ['content_editor'], 'role_keys_after' => []], $payloads);
    }

    public function test_suspend_restore_events_are_idempotent_and_keep_roles(): void
    {
        $owner = $this->withRole('super_admin');
        $staff = $this->withRole('booking_agent');
        $endpoint = '/admin/internal/staff-users/'.$staff->id.'/access';
        $this->actingAs($owner)->patchJson($endpoint, ['enabled' => false])->assertOk();
        $this->actingAs($owner)->patchJson($endpoint, ['enabled' => false])->assertOk();
        $suspend = $this->singleEvent('staff.suspended', (string) $staff->id);
        self::assertSame(['enabled_before' => true, 'enabled_after' => false], $this->metadata($suspend));

        $this->actingAs($owner)->patchJson($endpoint, ['enabled' => true])->assertOk();
        $this->actingAs($owner)->patchJson($endpoint, ['enabled' => true])->assertOk();
        $restore = $this->singleEvent('staff.restored', (string) $staff->id);
        self::assertSame(['enabled_before' => false, 'enabled_after' => true], $this->metadata($restore));
        $this->assertDatabaseHas('cms_role_user', ['user_id' => $staff->id]);
    }

    public function test_custom_role_creation_and_metadata_update_are_audited_without_description(): void
    {
        $owner = $this->withRole('super_admin');
        $created = $this->actingAs($owner)->postJson('/admin/internal/cms-roles', $this->newRolePayload())
            ->assertCreated();
        $id = (int) $created->json('data.id');
        $row = $this->singleEvent('role.created', (string) $id);
        self::assertSame(['tours.edit', 'tours.view'], $this->metadata($row)['permission_keys_after']);
        self::assertSame('custom_audit_agent', $this->metadata($row)['role_key']);

        $this->actingAs($owner)->putJson('/admin/internal/cms-roles/'.$id, [
            'name' => 'Audit Ops', 'description' => 'Sensitive staff context stays out of logs',
        ])->assertOk();
        $updated = $this->singleEvent('role.updated', (string) $id);
        self::assertSame(['name', 'description'], $this->metadata($updated)['changed_fields']);
        self::assertStringNotContainsString('Sensitive staff context', (string) $updated->metadata_json);
        $this->actingAs($owner)->putJson('/admin/internal/cms-roles/'.$id, [
            'name' => 'Audit Ops', 'description' => 'Sensitive staff context stays out of logs',
        ])->assertOk();
        self::assertSame(1, $this->eventCount('role.updated'));
    }

    public function test_permission_changes_audit_before_after_and_noop_does_not_log(): void
    {
        $owner = $this->withRole('super_admin');
        $created = $this->actingAs($owner)->postJson('/admin/internal/cms-roles', $this->newRolePayload())
            ->assertCreated();
        $id = (int) $created->json('data.id');

        $endpoint = '/admin/internal/cms-roles/'.$id.'/permissions';
        $this->actingAs($owner)->putJson($endpoint, [
            'permission_keys' => ['tours.view', 'tours.edit'],
        ])->assertOk();
        self::assertSame(0, $this->eventCount('role.permissions.changed'));

        $this->actingAs($owner)->putJson($endpoint, ['permission_keys' => ['tours.view']])->assertOk();
        $row = $this->singleEvent('role.permissions.changed', (string) $id);
        self::assertSame(['tours.edit', 'tours.view'], $this->metadata($row)['permission_keys_before']);
        self::assertSame(['tours.view'], $this->metadata($row)['permission_keys_after']);
    }

    public function test_role_deletion_keeps_snapshot_after_role_is_gone(): void
    {
        $owner = $this->withRole('super_admin');
        $created = $this->actingAs($owner)->postJson('/admin/internal/cms-roles', $this->newRolePayload())
            ->assertCreated();
        $id = (int) $created->json('data.id');

        $this->actingAs($owner)->deleteJson('/admin/internal/cms-roles/'.$id)->assertNoContent();
        $row = $this->singleEvent('role.deleted', (string) $id);
        self::assertSame('custom_audit_agent', $this->metadata($row)['role_key']);
        self::assertSame(['tours.edit', 'tours.view'], $this->metadata($row)['permission_keys_before']);
        $this->assertDatabaseMissing('cms_roles', ['id' => $id]);
    }

    public function test_rejected_admin_operations_do_not_emit_events(): void
    {
        $owner = $this->withRole('super_admin');
        $editor = $this->withRole('content_editor');
        $this->actingAs($editor)->postJson('/admin/internal/cms-roles', $this->newRolePayload())
            ->assertForbidden();
        $this->actingAs($owner)->putJson('/admin/internal/staff-users/'.$owner->id.'/roles', [
            'role_keys' => [],
        ])->assertUnprocessable();
        self::assertSame(0, DB::table('cms_audit_events')->count());
    }

    public function test_role_and_audit_insert_are_rolled_back_together(): void
    {
        $owner = $this->withRole('super_admin');
        try {
            DB::transaction(function () use ($owner): void {
                $this->actingAs($owner)->postJson('/admin/internal/cms-roles', $this->newRolePayload())
                    ->assertCreated();
                throw new RuntimeException('Rollback outer transaction');
            });
            self::fail('Expected deliberate rollback');
        } catch (RuntimeException $e) {
            self::assertSame('Rollback outer transaction', $e->getMessage());
        }
        $this->assertDatabaseMissing('cms_roles', ['key' => 'custom_audit_agent']);
        self::assertSame(0, DB::table('cms_audit_events')->count());
    }

    /** @return array{key: string, name: string, description: string, permission_keys: list<string>} */
    private function newRolePayload(): array
    {
        return [
            'key' => 'custom_audit_agent',
            'name' => 'Audit Agent',
            'description' => 'Operations role',
            'permission_keys' => ['tours.view', 'tours.edit'],
        ];
    }

    /** @return object */
    private function singleEvent(string $action, string $subjectId): object
    {
        $rows = DB::table('cms_audit_events')->where('action', $action)
            ->where('subject_id', $subjectId)->get();
        self::assertCount(1, $rows, 'Exactly one '.$action.' event expected for '.$subjectId);
        return $rows->first();
    }

    private function eventCount(string $action): int
    {
        return (int) DB::table('cms_audit_events')->where('action', $action)->count();
    }

    /** @return array<string, mixed> */
    private function metadata(object $row): array
    {
        /** @var array<string, mixed> $metadata */
        $metadata = json_decode((string) $row->metadata_json, true, 512, JSON_THROW_ON_ERROR);
        return $metadata;
    }

    private function withRole(string $key): User
    {
        $user = User::factory()->create();
        $role = CmsRole::query()->where('key', $key)->firstOrFail();
        $role->users()->attach($user->getKey());
        return $user;
    }
}
