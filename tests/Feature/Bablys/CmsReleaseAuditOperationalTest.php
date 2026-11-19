<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Cms\Audit\CmsAuditArchiveService;
use App\Domain\Cms\Audit\CmsAuditProductionChecks;
use App\Domain\Cms\Audit\CmsAuditRecorder;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** P2B.6C: audit privacy, immutable live storage, private archive, readiness. */
final class CmsReleaseAuditOperationalTest extends TestCase
{
    use RefreshDatabase;

    private string $archiveDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
        $this->archiveDir = storage_path('framework/testing/bablys-p2b6c/'.bin2hex(random_bytes(6)));
        config()->set('bablys_audit.archive_directory', $this->archiveDir);
        config()->set('bablys_audit.archive_key', 'base64:'.base64_encode(str_repeat('R', 32)));
        config()->set('bablys_audit.archive_after_days', 30);
        config()->set('bablys_audit.retention_days', 730);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->archiveDir);
        parent::tearDown();
    }

    public function test_owner_can_view_redacted_detail_but_regular_staff_cannot(): void
    {
        $owner = $this->withRole('super_admin');
        $editor = $this->withRole('content_editor');
        $id = app(CmsAuditRecorder::class)->record('staff.roles.changed', $owner, 'staff_user', 42, [
            'role_keys_after' => ['booking_agent'],
            'password' => 'do-not-expose-this',
            'api_token' => 'private-token-value',
        ]);

        $this->actingAs($owner)->getJson('/admin/internal/audit-logs/'.$id)
            ->assertOk()->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.metadata.role_keys_after.0', 'booking_agent')
            ->assertJsonMissingPath('data.metadata.password')
            ->assertDontSee('do-not-expose-this')
            ->assertDontSee('private-token-value');
        $this->actingAs($editor)->getJson('/admin/internal/audit-logs/'.$id)->assertForbidden();
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs')
            ->assertOk()->assertJsonMissingPath('data.0.metadata');
    }

    public function test_actual_role_mutation_produces_a_row_that_sql_cannot_update_or_delete(): void
    {
        $owner = $this->withRole('super_admin');
        $response = $this->actingAs($owner)->postJson('/admin/internal/cms-roles', [
            'key' => 'custom_release_immutable', 'name' => 'Immutable Test',
            'description' => null, 'permission_keys' => ['tours.view'],
        ])->assertCreated();
        $roleId = (int) $response->json('data.id');
        $event = DB::table('cms_audit_events')->where('action', 'role.created')
            ->where('subject_id', (string) $roleId)->first();
        self::assertNotNull($event);

        try {
            DB::table('cms_audit_events')->where('id', $event->id)->update(['action' => 'role.deleted']);
            self::fail('The database must reject audit UPDATE statements.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('immutable', $exception->getMessage());
        }
        try {
            DB::table('cms_audit_events')->where('id', $event->id)->delete();
            self::fail('The database must reject audit DELETE statements.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('immutable', $exception->getMessage());
        }
        $this->assertDatabaseHas('cms_audit_events', ['id' => $event->id, 'action' => 'role.created']);
    }

    public function test_encrypted_archive_can_be_verified_without_purging_live_events(): void
    {
        $owner = $this->withRole('super_admin');
        $this->travelTo(CarbonImmutable::parse('2024-01-15 10:00:00', 'UTC'));
        $id = app(CmsAuditRecorder::class)->record('role.created', $owner, 'cms_role', 25, [
            'role_key' => 'custom_release_archive',
        ]);
        $this->travelBack();

        $archive = app(CmsAuditArchiveService::class);
        self::assertSame(1, $archive->countForDay('2024-01-15'));
        self::assertSame(1, $archive->exportDay('2024-01-15')['row_count']);
        self::assertSame(1, $archive->verifyDay('2024-01-15')['row_count']);
        $ciphertext = (string) file_get_contents($this->archiveDir.'/bablys-audit-2024-01-15.v1.encjsonl');
        self::assertStringNotContainsString('custom_release_archive', $ciphertext);
        $this->assertDatabaseHas('cms_audit_events', ['id' => $id]);
    }

    public function test_readiness_inventory_detects_missing_key_without_touching_event_history(): void
    {
        $id = app(CmsAuditRecorder::class)->record('role.updated', null, 'cms_role', 7);
        config()->set('bablys_audit.archive_key', null);
        $checks = app(CmsAuditProductionChecks::class)->run();

        self::assertTrue($checks['audit_table']['passed']);
        self::assertTrue($checks['immutable_triggers']['passed']);
        self::assertFalse($checks['archive_key']['passed']);
        self::assertTrue($checks['retention_policy']['passed']);
        $this->assertDatabaseHas('cms_audit_events', ['id' => $id]);
    }

    public function test_filtered_viewer_queries_are_bounded_and_never_unwrap_metadata(): void
    {
        $owner = $this->withRole('super_admin');
        app(CmsAuditRecorder::class)->record('staff.created', $owner, 'staff_user', 10, ['password' => 'hidden']);
        app(CmsAuditRecorder::class)->record('role.updated', $owner, 'cms_role', 11);

        $this->actingAs($owner)->getJson('/admin/internal/audit-logs?action=staff.created&per_page=10')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject_id', '10')
            ->assertJsonMissingPath('data.0.metadata')
            ->assertDontSee('hidden');
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs?search[]=unexpected')
            ->assertUnprocessable()->assertJsonValidationErrors('search');
    }

    private function withRole(string $key): User
    {
        $user = User::factory()->create();
        $role = CmsRole::query()->where('key', $key)->firstOrFail();
        $role->users()->attach($user->getKey());

        return $user;
    }
}
