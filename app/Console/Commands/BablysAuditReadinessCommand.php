<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Cms\Audit\CmsAuditProductionChecks;
use Illuminate\Console\Command;

final class BablysAuditReadinessCommand extends Command
{
    protected $signature = 'bablys:audit:readiness';

    protected $description = 'Check Bablys immutable audit guards and private archive configuration';

    public function handle(CmsAuditProductionChecks $checks): int
    {
        $rows = $checks->run();
        foreach ($rows as $name => $row) {
            $status = $row['passed'] ? 'PASS' : 'FAIL';
            $this->line("[{$status}] {$name}: {$row['detail']}");
        }
        $this->warn('This does not certify off-site backups, database least privilege, retention lawfulness, or restore testing.');

        return count(array_filter($rows, static fn (array $row): bool => ! $row['passed'])) === 0
            ? self::SUCCESS
            : self::FAILURE;
    }
}
