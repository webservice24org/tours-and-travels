<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Cms\Audit\CmsAuditRecorder;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/** P2B.5D: HTTP append-only boundary, integrity, and atomic audit writes. */
final class CmsAuditIntegrityQaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_owner_cannot_mutate_or_delete_audit_history_via_http(): void
    {
        $owner = $this->owner();
        $id = $this->record($owner);
        $this->actingAs($owner)->postJson('/admin/internal/audit-logs', ['action' => 'forged.event'])
            ->assertStatus(405);
        $this->actingAs($owner)->putJson('/admin/internal/audit-logs/'.$id, ['action' => 'forged.event'])
            ->assertStatus(405);
        $this->actingAs($owner)->patchJson('/admin/internal/audit-logs/'.$id, ['metadata' => []])
            ->assertStatus(405);
        $this->actingAs($owner)->deleteJson('/admin/internal/audit-logs/'.$id)
            ->assertStatus(405);
        $this->assertDatabaseHas('cms_audit_events', ['id' => $id, 'action' => 'role.created']);
        self::assertSame(1, (int) DB::table('cms_audit_events')->count());
    }

    public function test_viewing_page_listing_options_and_details_does_not_write_audit_events(): void
    {
        $owner = $this->owner();
        $id = $this->record($owner);
        $countBefore = (int) DB::table('cms_audit_events')->count();
        $this->actingAs($owner)->get('/admin/audit-logs')->assertOk();
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs')->assertOk();
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs/options')->assertOk();
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs/'.$id)->assertOk();
        self::assertSame($countBefore, (int) DB::table('cms_audit_events')->count());
    }

    public function test_audit_event_is_rolled_back_when_business_transaction_fails(): void
    {
        $owner = $this->owner();
        try {
            DB::transaction(function () use ($owner): void {
                DB::table('cms_roles')->insert([
                    'key' => 'custom_qa_rollback',
                    'name' => 'QA Rollback Role',
                    'description' => null,
                    'is_system' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->record($owner);
                throw new RuntimeException('Intentional business rollback');
            });
            self::fail('Transaction must roll back.');
        } catch (RuntimeException $exception) {
            self::assertSame('Intentional business rollback', $exception->getMessage());
        }
        $this->assertDatabaseMissing('cms_roles', ['key' => 'custom_qa_rollback']);
        self::assertSame(0, (int) DB::table('cms_audit_events')->count());
    }

    public function test_invalid_event_action_cannot_create_an_audit_record(): void
    {
        $owner = $this->owner();
        try {
            app(CmsAuditRecorder::class)->record('role.created;DROP TABLE users', $owner, 'cms_role', 9);
            self::fail('The invalid audit action must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(0, (int) DB::table('cms_audit_events')->count());
        }
    }

    public function test_oversized_metadata_is_rejected_atomically(): void
    {
        $owner = $this->owner();
        $metadata = [];
        for ($index = 0; $index < 24; $index++) {
            $metadata['qa_field_'.$index] = str_repeat('q', 900);
        }
        try {
            app(CmsAuditRecorder::class)->record('role.created', $owner, 'cms_role', 9, $metadata);
            self::fail('Oversized audit metadata must be rejected.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(0, (int) DB::table('cms_audit_events')->count());
        }
    }

    public function test_repeated_noop_mutation_does_not_generate_spurious_events(): void
    {
        $owner = $this->owner();
        $staff = $this->withRole('content_editor');
        $path = '/admin/internal/staff-users/'.$staff->id.'/roles';
        $this->actingAs($owner)->putJson($path, ['role_keys' => ['content_editor']])->assertOk();
        $this->actingAs($owner)->putJson($path, ['role_keys' => ['content_editor']])->assertOk();
        self::assertSame(0, (int) DB::table('cms_audit_events')->where('action', 'staff.roles.changed')->count());
        $this->actingAs($owner)->putJson($path, ['role_keys' => ['booking_agent']])->assertOk();
        self::assertSame(1, (int) DB::table('cms_audit_events')->where('action', 'staff.roles.changed')->count());
    }

    public function test_deleting_a_subject_does_not_remove_its_historical_audit_event(): void
    {
        $owner = $this->owner();
        $staff = User::factory()->create();
        $eventId = app(CmsAuditRecorder::class)->record('staff.created', $owner, 'staff_user', $staff->id, []);
        $staff->delete();
        $this->assertDatabaseHas('cms_audit_events', ['id' => $eventId, 'subject_id' => (string) $staff->id]);
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs/'.$eventId)
            ->assertOk()->assertJsonPath('data.subject_id', (string) $staff->id);
    }

    private function record(User $actor): string
    {
        return app(CmsAuditRecorder::class)->record('role.created', $actor, 'cms_role', 18, ['role_key' => 'custom_qa']);
    }

    private function owner(): User
    {
        return $this->withRole('super_admin');
    }

    private function withRole(string $key): User
    {
        $user = User::factory()->create();
        $role = CmsRole::query()->where('key', $key)->firstOrFail();
        $role->users()->attach($user->getKey());
        return $user;
    }
}
