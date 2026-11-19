<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Cms\Audit\CmsAuditArchiveService;
use Illuminate\Console\Command;
use Throwable;

final class BablysAuditArchiveCommand extends Command
{
    protected $signature = 'bablys:audit:archive
        {date : UTC date in YYYY-MM-DD format}
        {--commit : Write an encrypted, signed archive; default is dry-run}
        {--verify : Verify an existing archive instead of exporting}';

    protected $description = 'Preview, create, or verify an encrypted Bablys audit-day archive (never deletes source rows)';

    public function handle(CmsAuditArchiveService $archive): int
    {
        $day = (string) $this->argument('date');
        if ($this->option('verify') && $this->option('commit')) {
            $this->error('Choose either --commit or --verify.');
            return self::FAILURE;
        }
        try {
            if ($this->option('verify')) {
                $result = $archive->verifyDay($day);
                $this->info("Verified {$result['row_count']} encrypted Bablys audit events for {$day}.");
                return self::SUCCESS;
            }
            if (! $this->option('commit')) {
                $count = $archive->countForDay($day);
                $this->line("Preview only: {$count} Bablys audit events for {$day}. No file created, no rows deleted.");
                return self::SUCCESS;
            }
            $result = $archive->exportDay($day);
            $this->info("Encrypted and verified {$result['row_count']} audit events for {$day} (SHA-256 {$result['sha256']}).");
            $this->warn('Source database records were retained. Move encrypted archive and signed manifest to approved off-site storage.');
            return self::SUCCESS;
        } catch (Throwable $e) {
            // Operational errors may be shown to a terminal admin, never a public route.
            $this->error('Audit archive operation failed: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
