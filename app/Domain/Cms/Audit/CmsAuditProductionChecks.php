<?php

declare(strict_types=1);

namespace App\Domain\Cms\Audit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class CmsAuditProductionChecks
{
    /**
     * @return array<string, array{passed:bool, detail:string}>
     */
    public function run(): array
    {
        $checks = [];
        $table = Schema::hasTable('cms_audit_events');
        $checks['audit_table'] = ['passed' => $table, 'detail' => $table ? 'Present' : 'Missing'];

        $driver = DB::connection()->getDriverName();
        $triggerNames = [];
        try {
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                // A low-privilege runtime DB user may not be allowed to list triggers.
                // Run this verification using a separate maintenance account.
                $rows = DB::select("SELECT TRIGGER_NAME AS name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'cms_audit_events'");
                foreach ($rows as $row) {
                    $triggerNames[] = (string) $row->name;
                }
            } elseif ($driver === 'sqlite') {
                $rows = DB::select("SELECT name FROM sqlite_master WHERE type = 'trigger' AND tbl_name = 'cms_audit_events'");
                foreach ($rows as $row) {
                    $triggerNames[] = (string) $row->name;
                }
            }
        } catch (Throwable) {
            // Never guess that database-level protection is enabled.
            $triggerNames = [];
        }
        $protected = in_array('cms_audit_events_no_update', $triggerNames, true)
            && in_array('cms_audit_events_no_delete', $triggerNames, true);
        $checks['immutable_triggers'] = [
            'passed' => $protected,
            'detail' => $protected ? 'UPDATE and DELETE guards visible' : 'Missing or not visible; verify using DBA account',
        ];

        $archive = app(CmsAuditArchiveService::class);
        $key = $archive->keyConfigured();
        $checks['archive_key'] = ['passed' => $key, 'detail' => $key ? 'Valid dedicated 32-byte key' : 'Missing or invalid key'];
        $directory = $archive->directoryIsPrivate();
        $checks['archive_directory'] = [
            'passed' => $directory,
            'detail' => $directory ? 'Configured outside web root' : 'Directory is missing/invalid or inside web root',
        ];

        $retention = (int) config('bablys_audit.retention_days', 0);
        $archiveAfter = (int) config('bablys_audit.archive_after_days', 0);
        $ageValid = $archiveAfter >= 30 && $retention >= $archiveAfter;
        $checks['retention_policy'] = [
            'passed' => $ageValid,
            'detail' => $ageValid
                ? "Archive eligible after {$archiveAfter} days; {$retention}-day retention is a POLICY target only (no automatic purge)"
                : 'Retention/archive thresholds invalid',
        ];

        return $checks;
    }
}
