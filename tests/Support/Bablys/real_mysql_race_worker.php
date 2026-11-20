<?php

declare(strict_types=1);

/**
 * P3E.5B.3D test-only child process. Not a route, controller, or Artisan command.
 * Requires a dedicated *_race_test MySQL database and explicit opt-in.
 */

use App\Domain\Travel\Bookings\TourBookingLifecycleManager;
use App\Domain\Travel\Bookings\TourBookingOfflinePaymentReconciler;
use App\Domain\Travel\Bookings\TourBookingQuoteAcceptanceManager;
use App\Domain\Travel\Bookings\TourBookingRequestExpiryManager;
use App\Domain\Travel\Bookings\TourBookingTimeoutPolicy;
use App\Domain\Travel\Bookings\TourBookingRequestManager;
use App\Domain\Travel\Bookings\TourSeatAllocationManager;
use App\Models\Travel\Tour;
use App\Models\Travel\TourBooking;
use App\Models\Travel\TourBookingRequest;
use App\Models\Travel\TourDeparture;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** @return array<string, mixed> */
function raceDecodeEnv(string $name): array
{
    $raw = getenv($name);
    if (! is_string($raw) || $raw === '') {
        throw new RuntimeException('Missing test worker configuration.');
    }
    $json = base64_decode($raw, true);
    $decoded = is_string($json) ? json_decode($json, true) : null;
    if (! is_array($decoded)) {
        throw new RuntimeException('Invalid test worker configuration.');
    }

    return $decoded;
}

try {
    if (getenv('BABLYS_REAL_MYSQL_RACE') !== '1' || ! app()->environment('testing')) {
        throw new RuntimeException('Race worker requires explicit testing opt-in.');
    }

    $connection = raceDecodeEnv('BABLYS_RACE_DB_CONFIG_BASE64');
    $job = raceDecodeEnv('BABLYS_RACE_JOB_BASE64');
    $expected = (string) getenv('BABLYS_RACE_EXPECTED_DATABASE');
    if (($connection['driver'] ?? null) !== 'mysql' || ($connection['database'] ?? null) !== $expected
        || preg_match('/^[a-zA-Z0-9_]+_race_test$/D', $expected) !== 1
        || ! empty($connection['url']) || isset($connection['read']) || isset($connection['write'])) {
        throw new RuntimeException('Unsafe or mismatched race worker database configuration.');
    }

    config()->set('database.connections.bablys_race_worker', $connection);
    config()->set('database.default', 'bablys_race_worker');
    DB::purge('bablys_race_worker');
    DB::setDefaultConnection('bablys_race_worker');
    DB::connection()->statement('SET SESSION innodb_lock_wait_timeout = 15');
    $actualRow = DB::selectOne('SELECT DATABASE() AS db_name');
    if (! is_object($actualRow) || (string) ($actualRow->db_name ?? '') !== $expected) {
        throw new RuntimeException('Race worker database connection mismatch.');
    }

    $directory = (string) getenv('BABLYS_RACE_MARKER_DIRECTORY');
    $slot = (string) getenv('BABLYS_RACE_SLOT');
    if (! is_dir($directory) || ! in_array($slot, ['0', '1'], true)) {
        throw new RuntimeException('Invalid process barrier.');
    }
    if (file_put_contents($directory.'/'.$slot.'.ready', 'ready', LOCK_EX) === false) {
        throw new RuntimeException('Failed to signal worker readiness.');
    }
    $deadline = microtime(true) + 20;
    while (! is_file($directory.'/go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Timed out waiting for synchronized release.');
        }
        usleep(10_000);
    }

    $actor = User::query()->findOrFail((int) ($job['actor_id'] ?? 0));
    $kind = (string) ($job['kind'] ?? '');
    $id = match ($kind) {
        'allocate' => (int) app(TourSeatAllocationManager::class)->commit(
            $actor,
            Tour::query()->findOrFail((int) ($job['tour_id'] ?? 0)),
            TourDeparture::query()->findOrFail((int) ($job['departure_id'] ?? 0)),
            (string) ($job['reference'] ?? ''), 'standard-bdt', 1
        )->getKey(),
        'convert' => (int) app(TourBookingRequestManager::class)->convertVerified(
            $actor, TourBookingRequest::query()->findOrFail((int) ($job['request_id'] ?? 0))
        )->getKey(),
        'accept_quote' => (int) app(TourBookingQuoteAcceptanceManager::class)->acceptCustomer(
            $actor,
            TourBookingRequest::query()->findOrFail((int) ($job['request_id'] ?? 0)),
            (int) ($job['revision'] ?? 0),
            (string) ($job['fingerprint'] ?? '')
        )['revision'],
        'reconcile' => (function () use ($actor, $job): int {
            config()->set('bablys_booking_payment.manual_reconciliation_enabled', true);
            return (int) app(TourBookingOfflinePaymentReconciler::class)->recordExternallyVerifiedFullPayment(
                $actor, TourBooking::query()->findOrFail((int) ($job['booking_id'] ?? 0)),
                (int) ($job['amount_minor'] ?? 0),
                (string) ($job['currency'] ?? ''),
                (string) ($job['evidence_reference'] ?? '')
            )->getKey();
        })(),
        'cancel' => (int) app(TourBookingLifecycleManager::class)->cancel(
            $actor, TourBooking::query()->findOrFail((int) ($job['booking_id'] ?? 0))
        )->getKey(),
        'confirm' => (int) app(TourBookingLifecycleManager::class)->confirm(
            $actor, TourBooking::query()->findOrFail((int) ($job['booking_id'] ?? 0))
        )->getKey(),
        'flag_overdue' => (int) app(TourBookingTimeoutPolicy::class)->flagOverdueBatch(200)['flagged'],
        'expire_batch' => (int) app(TourBookingRequestExpiryManager::class)->expireBatch(200)['expired'],
        'withdraw_customer' => (int) app(TourBookingRequestManager::class)->withdrawCustomer(
            $actor, TourBookingRequest::query()->findOrFail((int) ($job['request_id'] ?? 0))
        )->getKey(),
        default => throw new RuntimeException('Unknown test-only operation.'),
    };
    echo json_encode(['ok' => true, 'id' => $id], JSON_THROW_ON_ERROR)."\n";
} catch (ValidationException $e) {
    echo json_encode(['ok' => false, 'kind' => 'validation'], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $e) {
    // Never print SQL parameters, database credentials, PII or guest tokens.
    echo json_encode(['ok' => false, 'kind' => get_class($e)], JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
