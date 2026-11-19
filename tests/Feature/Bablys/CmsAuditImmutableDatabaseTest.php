<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Cms\Audit\CmsAuditRecorder;
use App\Domain\Cms\Audit\CmsAuditProductionChecks;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CmsAuditImmutableDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_protected_table_remains_insertable_through_existing_recorder(): void
    {
        $id = app(CmsAuditRecorder::class)->record('staff.created', null, 'staff_user', 42);
        $this->assertDatabaseHas('cms_audit_events', ['id' => $id, 'action' => 'staff.created']);
    }

    public function test_raw_sql_update_is_rejected_by_database_trigger(): void
    {
        $id = app(CmsAuditRecorder::class)->record('role.updated', null, 'cms_role', 42);

        try {
            DB::table('cms_audit_events')->where('id', $id)->update(['action' => 'role.deleted']);
            self::fail('Database update must be rejected even without HTTP authorization.');
        } catch (QueryException $e) {
            self::assertStringContainsString('CMS audit history is immutable', $e->getMessage());
        }
        $this->assertDatabaseHas('cms_audit_events', ['id' => $id, 'action' => 'role.updated']);
    }

    public function test_raw_sql_delete_is_rejected_by_database_trigger(): void
    {
        $id = app(CmsAuditRecorder::class)->record('staff.suspended', null, 'staff_user', 14);

        try {
            DB::table('cms_audit_events')->where('id', $id)->delete();
            self::fail('Database deletion must be rejected even without HTTP authorization.');
        } catch (QueryException $e) {
            self::assertStringContainsString('CMS audit history is immutable', $e->getMessage());
        }
        $this->assertDatabaseHas('cms_audit_events', ['id' => $id]);
    }

    public function test_guard_inventory_reports_both_immutable_triggers(): void
    {
        config()->set('bablys_audit.archive_key', 'base64:'.base64_encode(str_repeat('z', 32)));
        $checks = app(CmsAuditProductionChecks::class)->run();
        self::assertTrue($checks['immutable_triggers']['passed']);
    }
}
