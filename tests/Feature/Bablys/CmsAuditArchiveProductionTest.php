<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Cms\Audit\CmsAuditArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

final class CmsAuditArchiveProductionTest extends TestCase
{
    use RefreshDatabase;

    private string $testArchiveDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testArchiveDir = storage_path('framework/testing/bablys-audit-p2b6b/'.bin2hex(random_bytes(6)));
        config()->set('bablys_audit.archive_directory', $this->testArchiveDir);
        config()->set('bablys_audit.archive_key', 'base64:'.base64_encode(str_repeat('K', 32)));
        config()->set('bablys_audit.archive_after_days', 30);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testArchiveDir);
        parent::tearDown();
    }

    public function test_cli_defaults_to_preview_without_writing_files(): void
    {
        $this->insertOldEvent();
        $this->artisan('bablys:audit:archive', ['date' => '2024-01-15'])->assertExitCode(0);
        self::assertFalse(is_dir($this->testArchiveDir));
        self::assertSame(1, (int) DB::table('cms_audit_events')->count());
    }

    public function test_encrypted_archive_roundtrip_preserves_source_and_hides_plaintext(): void
    {
        $this->insertOldEvent();
        $service = app(CmsAuditArchiveService::class);
        $result = $service->exportDay('2024-01-15');
        self::assertSame(1, $result['row_count']);
        $filename = $this->testArchiveDir.'/bablys-audit-2024-01-15.v1.encjsonl';
        $raw = (string) file_get_contents($filename);
        self::assertStringNotContainsString('role.permissions.changed', $raw);
        self::assertStringNotContainsString('internal-note', $raw);
        self::assertSame(1, $service->verifyDay('2024-01-15')['row_count']);
        self::assertSame(1, (int) DB::table('cms_audit_events')->count());
    }

    public function test_modified_ciphertext_is_detected(): void
    {
        $this->insertOldEvent();
        $service = app(CmsAuditArchiveService::class);
        $service->exportDay('2024-01-15');
        $path = $this->testArchiveDir.'/bablys-audit-2024-01-15.v1.encjsonl';
        file_put_contents($path, 'tampered'.PHP_EOL);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('checksum mismatch');
        $service->verifyDay('2024-01-15');
    }

    public function test_duplicate_archive_is_blocked_without_overwrite(): void
    {
        $this->insertOldEvent();
        $service = app(CmsAuditArchiveService::class);
        $service->exportDay('2024-01-15');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');
        $service->exportDay('2024-01-15');
    }

    public function test_recent_day_cannot_be_archived(): void
    {
        $service = app(CmsAuditArchiveService::class);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('too recent');
        $service->exportDay(now('UTC')->format('Y-m-d'));
    }

    public function test_missing_key_disables_archive(): void
    {
        config()->set('bablys_audit.archive_key', null);
        self::assertFalse(app(CmsAuditArchiveService::class)->keyConfigured());
        $this->expectException(RuntimeException::class);
        app(CmsAuditArchiveService::class)->exportDay('2024-01-15');
    }

    public function test_refuses_web_accessible_archive_directory(): void
    {
        config()->set('bablys_audit.archive_directory', public_path('bablys-audit-do-not-create'));
        self::assertFalse(app(CmsAuditArchiveService::class)->directoryIsPrivate());
    }

    private function insertOldEvent(): void
    {
        DB::table('cms_audit_events')->insert([
            'id' => 'ac66cddb-07f4-46f0-a095-6c3df8fb88c1',
            'actor_id' => null,
            'action' => 'role.permissions.changed',
            'subject_type' => 'cms_role',
            'subject_id' => '5',
            'metadata_json' => '{"note":"internal-note"}',
            'ip_address' => null,
            'user_agent' => null,
            'occurred_at' => '2024-01-15 10:02:00',
        ]);
    }
}
