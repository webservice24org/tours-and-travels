<?php

declare(strict_types=1);

namespace App\Domain\Cms\Audit;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;
use stdClass;

/**
 * Encrypted, verifiable, per-UTC-day read-only audit archives.
 *
 * This service NEVER deletes audit records. Live-table retention and any
 * legal-hold exceptions must be handled in an independently approved DBA plan.
 */
final class CmsAuditArchiveService
{
    public function countForDay(string $day): int
    {
        [$start, $end] = $this->bounds($day);

        return (int) DB::table('cms_audit_events')
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $end)
            ->count();
    }

    /**
     * @return array{day:string, row_count:int, sha256:string, size_bytes:int, created_at:string}
     */
    public function exportDay(string $day): array
    {
        [$start, $end] = $this->bounds($day);
        $this->assertAge($day);
        $key = $this->key();
        $dir = $this->directory();
        $base = $dir.DIRECTORY_SEPARATOR.'bablys-audit-'.$day.'.v1';
        $archivePath = $base.'.encjsonl';
        $manifestPath = $base.'.manifest.json';
        $lockPath = $base.'.lock';

        $lock = fopen($lockPath, 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Another audit archival operation is already running for this date.');
        }

        $temp = null;
        $out = null;
        try {
            if (file_exists($archivePath) || file_exists($manifestPath)) {
                throw new RuntimeException('Audit archive already exists. Verify it; never overwrite an existing archive.');
            }

            $temp = $base.'.'.bin2hex(random_bytes(8)).'.part';
            $out = fopen($temp, 'x+b');
            if ($out === false) {
                throw new RuntimeException('Cannot create the private audit archive.');
            }
            @chmod($temp, 0600);
            $count = 0;

            // Explicit columns; neither request input nor arbitrary model properties
            // are ever serialized. Row order is stable for equal timestamps.
            $records = DB::table('cms_audit_events')
                ->select(['id', 'actor_id', 'action', 'subject_type', 'subject_id',
                    'metadata_json', 'ip_address', 'user_agent', 'occurred_at'])
                ->where('occurred_at', '>=', $start)
                ->where('occurred_at', '<', $end)
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->cursor();

            foreach ($records as $record) {
                /** @var stdClass $record */
                $plaintext = json_encode((array) $record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $iv = random_bytes(12);
                $tag = '';
                $encrypted = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
                if ($encrypted === false || strlen($tag) !== 16) {
                    throw new RuntimeException('Audit archive encryption failed.');
                }
                $line = base64_encode($iv.$tag.$encrypted)."\n";
                if (fwrite($out, $line) !== strlen($line)) {
                    throw new RuntimeException('Short audit archive write.');
                }
                ++$count;
            }

            if ($count === 0) {
                throw new RuntimeException('No audit events exist on that UTC date; no archive created.');
            }

            if (! fflush($out)) {
                throw new RuntimeException('Audit archive flush failed.');
            }
            fclose($out);
            $out = null;

            $sha = hash_file('sha256', $temp);
            $bytes = filesize($temp);
            if ($sha === false || $bytes === false) {
                throw new RuntimeException('Cannot hash completed archive.');
            }

            $payload = [
                'format' => 'bablys-audit-archive-v1',
                'day' => $day,
                'row_count' => $count,
                'sha256' => $sha,
                'size_bytes' => $bytes,
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
            $manifest = $payload;
            $manifest['hmac_sha256'] = $this->mac($payload, $key);
            $manifestJson = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            // This pair cannot be transactionally renamed across all filesystems.
            // A crash between renames must be recovered manually, never by overwriting.
            if (! rename($temp, $archivePath)) {
                throw new RuntimeException('Cannot commit encrypted archive file.');
            }
            $temp = null;
            @chmod($archivePath, 0600);
            $manifestTemp = $manifestPath.'.'.bin2hex(random_bytes(8)).'.part';
            try {
                if (file_put_contents($manifestTemp, $manifestJson, LOCK_EX) !== strlen($manifestJson)) {
                    throw new RuntimeException('Cannot write audit archive manifest.');
                }
                @chmod($manifestTemp, 0600);
                if (! rename($manifestTemp, $manifestPath)) {
                    throw new RuntimeException('Cannot commit audit archive manifest.');
                }
            } finally {
                if (file_exists($manifestTemp)) {
                    @unlink($manifestTemp);
                }
            }
            @chmod($manifestPath, 0600);

            // Verify both cryptography and count after commit.
            $this->verifyDay($day);

            return [
                'day' => $day,
                'row_count' => $count,
                'sha256' => $sha,
                'size_bytes' => $bytes,
                'created_at' => $payload['created_at'],
            ];
        } finally {
            if (is_resource($out)) {
                fclose($out);
            }
            if ($temp !== null && file_exists($temp)) {
                @unlink($temp);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array{day:string, row_count:int, sha256:string} */
    public function verifyDay(string $day): array
    {
        $this->bounds($day);
        $key = $this->key();
        $dir = $this->directory(false);
        $base = $dir.DIRECTORY_SEPARATOR.'bablys-audit-'.$day.'.v1';
        $archivePath = $base.'.encjsonl';
        $manifestPath = $base.'.manifest.json';
        if (! is_file($archivePath) || ! is_file($manifestPath)) {
            throw new RuntimeException('Audit archive or signed manifest is missing.');
        }

        $manifestJson = file_get_contents($manifestPath);
        if ($manifestJson === false) {
            throw new RuntimeException('Cannot read audit manifest.');
        }
        /** @var mixed $decoded */
        $decoded = json_decode($manifestJson, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('Invalid audit manifest structure.');
        }
        $payload = [];
        foreach (['format', 'day', 'row_count', 'sha256', 'size_bytes', 'created_at'] as $field) {
            if (! array_key_exists($field, $decoded)) {
                throw new RuntimeException('Audit manifest is incomplete.');
            }
            $payload[$field] = $decoded[$field];
        }
        if ($payload['format'] !== 'bablys-audit-archive-v1'
            || $payload['day'] !== $day
            || ! is_int($payload['row_count'])
            || $payload['row_count'] < 1
            || ! is_int($payload['size_bytes'])
            || ! is_string($payload['sha256'])
            || ! is_string($payload['created_at'])
            || ! is_string($decoded['hmac_sha256'] ?? null)
            || ! hash_equals($this->mac($payload, $key), $decoded['hmac_sha256'])
        ) {
            throw new RuntimeException('Audit manifest authentication failed.');
        }

        $sha = hash_file('sha256', $archivePath);
        $size = filesize($archivePath);
        if ($sha === false || $size === false
            || ! hash_equals($payload['sha256'], $sha)
            || $size !== $payload['size_bytes']) {
            throw new RuntimeException('Encrypted audit archive checksum mismatch.');
        }

        $count = 0;
        $file = fopen($archivePath, 'rb');
        if ($file === false) {
            throw new RuntimeException('Cannot open encrypted audit archive.');
        }
        try {
            while (($line = fgets($file)) !== false) {
                $blob = base64_decode(trim($line), true);
                if ($blob === false || strlen($blob) < 29) {
                    throw new RuntimeException('Invalid encrypted audit archive record.');
                }
                $plaintext = openssl_decrypt(substr($blob, 28), 'aes-256-gcm', $key,
                    OPENSSL_RAW_DATA, substr($blob, 0, 12), substr($blob, 12, 16));
                if ($plaintext === false) {
                    throw new RuntimeException('Audit archive record authentication failed.');
                }
                /** @var mixed $row */
                $row = json_decode($plaintext, true, 32, JSON_THROW_ON_ERROR);
                if (! is_array($row)
                    || ! is_string($row['id'] ?? null)
                    || ! is_string($row['occurred_at'] ?? null)
                    || substr($row['occurred_at'], 0, 10) !== $day) {
                    throw new RuntimeException('Audit archive contains invalid event data.');
                }
                ++$count;
            }
            if (! feof($file)) {
                throw new RuntimeException('Audit archive could not be fully read.');
            }
        } finally {
            fclose($file);
        }

        if ($count !== $payload['row_count']) {
            throw new RuntimeException('Audit archive row count mismatch.');
        }

        return ['day' => $day, 'row_count' => $count, 'sha256' => $sha];
    }

    public function keyConfigured(): bool
    {
        try {
            $this->key();
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function directoryIsPrivate(): bool
    {
        try {
            $this->directory(false);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /** @return array{string,string} */
    private function bounds(string $day): array
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $day) {
            throw new RuntimeException('Date must be a valid UTC date formatted YYYY-MM-DD.');
        }

        return [$date->format('Y-m-d').' 00:00:00', $date->modify('+1 day')->format('Y-m-d').' 00:00:00'];
    }

    private function assertAge(string $day): void
    {
        $minimum = (int) config('bablys_audit.archive_after_days', 90);
        if ($minimum < 30) {
            throw new RuntimeException('Audit archive minimum age must be at least 30 days.');
        }
        $cutoff = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        if ($day >= $cutoff->modify('-'.$minimum.' days')->format('Y-m-d')) {
            throw new RuntimeException('Audit archive date is too recent.');
        }
    }

    private function key(): string
    {
        $configured = config('bablys_audit.archive_key');
        if (! is_string($configured) || ! str_starts_with($configured, 'base64:')) {
            throw new RuntimeException('Configure a dedicated base64: audit archive key before exporting or verifying.');
        }
        $decoded = base64_decode(substr($configured, 7), true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new RuntimeException('Audit archive key must contain exactly 32 random bytes.');
        }
        return $decoded;
    }

    private function directory(bool $create = true): string
    {
        $configured = config('bablys_audit.archive_directory');
        if (! is_string($configured) || trim($configured) === '') {
            throw new RuntimeException('Audit archive directory must be configured.');
        }
        // Require absolute paths and resolve the nearest existing parent so that
        // a nonexistent directory below public/ cannot slip through this check.
        if (! str_starts_with($configured, '/')
            && preg_match('/\A[A-Za-z]:[\\\\\/]/', $configured) !== 1) {
            throw new RuntimeException('Audit archive directory must be an absolute path.');
        }
        $public = realpath(public_path());
        if ($public === false) {
            throw new RuntimeException('Public directory could not be verified.');
        }
        $ancestor = $configured;
        while (! file_exists($ancestor)) {
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                throw new RuntimeException('Cannot resolve audit archive directory ancestry.');
            }
            $ancestor = $parent;
        }
        $resolvedAncestor = realpath($ancestor);
        if ($resolvedAncestor === false) {
            throw new RuntimeException('Audit archive directory ancestry could not be verified.');
        }
        $normalizedAncestor = strtolower(str_replace('\\', '/', rtrim($resolvedAncestor, '/\\')));
        $web = strtolower(str_replace('\\', '/', rtrim($public, '/\\')));
        if ($normalizedAncestor === $web || str_starts_with($normalizedAncestor, $web.'/')) {
            throw new RuntimeException('Audit archives cannot be stored in the public web directory.');
        }
        if ($create && ! is_dir($configured) && ! mkdir($configured, 0700, true) && ! is_dir($configured)) {
            throw new RuntimeException('Cannot create private audit directory.');
        }
        $real = realpath($configured);
        if ($real === false && ! $create) {
            return $configured;
        }
        if ($real === false || ! is_dir($real)) {
            throw new RuntimeException('Audit archive path cannot be verified.');
        }
        if ($create && ! is_writable($real)) {
            throw new RuntimeException('Audit archive directory is not writable.');
        }
        return $real;
    }

    /** @param array<string, mixed> $payload */
    private function mac(array $payload, string $key): string
    {
        $macKey = hash_hmac('sha256', 'bablys-audit-manifest-v1', $key, true);
        return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $macKey);
    }
}
