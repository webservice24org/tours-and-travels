<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cms_audit_events')) {
            throw new RuntimeException('P2B.5A cms_audit_events table must exist first.');
        }

        $driver = DB::connection()->getDriverName();

        // DDL is intentionally additive. Do not silently replace existing triggers.
        // A migration account needs TRIGGER privilege; the web app account must not.
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::unprepared("CREATE TRIGGER `cms_audit_events_no_update` BEFORE UPDATE ON `cms_audit_events` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CMS audit history is immutable'");
            DB::unprepared("CREATE TRIGGER `cms_audit_events_no_delete` BEFORE DELETE ON `cms_audit_events` FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'CMS audit history is immutable'");

            return;
        }

        if ($driver === 'sqlite') {
            // Test/development parity: legacy test fixtures must be INSERTed, never UPDATEd.
            DB::unprepared("CREATE TRIGGER cms_audit_events_no_update BEFORE UPDATE ON cms_audit_events FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'CMS audit history is immutable'); END");
            DB::unprepared("CREATE TRIGGER cms_audit_events_no_delete BEFORE DELETE ON cms_audit_events FOR EACH ROW BEGIN SELECT RAISE(ABORT, 'CMS audit history is immutable'); END");

            return;
        }

        throw new RuntimeException('P2B.6B immutable audit triggers support MySQL/MariaDB and SQLite only.');
    }

    public function down(): void
    {
        // Fail closed. An automated migrate:rollback must never silently remove
        // audit protection. Changes require a reviewed DBA maintenance procedure.
        throw new RuntimeException('P2B.6B audit protection cannot be rolled back automatically. See production runbook.');
    }
};
