<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Bookings\TourBookingLifecycleManager;
use App\Domain\Travel\Bookings\TourBookingQuoteAcceptanceManager;
use App\Domain\Travel\Bookings\TourBookingRequestManager;
use App\Models\Cms\CmsRole;
use App\Models\Travel\Destination;
use App\Models\Travel\Tour;
use App\Models\Travel\TourBooking;
use App\Models\Travel\TourBookingRequest;
use App\Models\Travel\TourCategory;
use App\Models\Travel\TourDeparture;
use App\Models\Travel\TourPriceVariant;
use App\Models\User;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * P3E.5B.3D deliberately does NOT use RefreshDatabase: its enclosing test
 * transaction would make fixture rows invisible to independent MySQL workers.
 *
 * Run ONLY with BABLYS_REAL_MYSQL_RACE=1, APP_ENV=testing and a dedicated
 * database whose *actual* selected name ends in _race_test. The suite uses
 * normal migrations (never migrate:fresh), committed fixtures, 2 PHP child
 * processes, and a third DB connection holding a real SELECT FOR UPDATE lock.
 * The test database is not wiped automatically and is NEVER production-safe.
 */
final class TourBookingRealMySqlConcurrencyTest extends TestCase
{
    private string $raceDatabase = '';

    /** @var array<string, mixed> */
    private array $raceConnection = [];

    public function test_independent_mysql_workers_preserve_booking_invariants_under_contention(): void
    {
        if (getenv('BABLYS_REAL_MYSQL_RACE') !== '1') {
            $this->markTestSkipped('Explicit opt-in required. This is NOT evidence that MySQL concurrency passed.');
        }
        $this->assertDedicatedDatabase();
        self::assertSame(0, Artisan::call('migrate', ['--force' => true]), 'Dedicated race-test schema migration failed.');
        self::assertTrue(Schema::hasTable('travel_tour_booking_offline_payments'));
        self::assertTrue(Schema::hasTable('travel_booking_request_quote_versions'));
        $this->seed(BablysCmsRbacSeeder::class);

        $staff = $this->raceStaff();
        $customer = User::factory()->create(['email_verified_at' => now('UTC')]);
        $prefix = 'race-' . bin2hex(random_bytes(5));

        // A. Same reference, same last seat: both attempts must converge on ONE allocation.
        [$tourA, $departureA] = $this->raceTour($prefix . '-same', 1);
        $sameRef = 'RACE-SAME-' . $prefix;
        $jobA = $this->allocateJob($staff, $tourA, $departureA, $sameRef);
        $same = $this->racePair('travel_tours', (int) $tourA->getKey(), $jobA, $jobA);
        $this->assertSuccessfulPair($same, 'same allocation reference');
        self::assertSame($same[0]['id'] ?? null, $same[1]['id'] ?? null);
        self::assertSame(1, (int) $departureA->fresh()->seats_committed);
        self::assertSame(1, DB::table('travel_tour_seat_allocations')->where('reference_key', $sameRef)->count());

        // B. Different references competing for the same last seat: one winner, one rejection.
        [$tourB, $departureB] = $this->raceTour($prefix . '-last', 1);
        $last = $this->racePair(
            'travel_tours',
            (int) $tourB->getKey(),
            $this->allocateJob($staff, $tourB, $departureB, 'RACE-LAST-A-' . $prefix),
            $this->allocateJob($staff, $tourB, $departureB, 'RACE-LAST-B-' . $prefix)
        );
        $this->assertOneSuccessOneValidation($last, 'last seat contention');
        self::assertSame(1, (int) $departureB->fresh()->seats_committed);
        self::assertSame(1, DB::table('travel_tour_seat_allocations')
            ->where('tour_id', (int) $tourB->getKey())->where('status', 'committed')->count());

        // C. Same traveler-approved request converted twice concurrently: exactly one booking.
        [$tourC, $departureC] = $this->raceTour($prefix . '-convert', 2);
        $requestC = $this->approvedRequest($customer, $tourC, $departureC);
        $convertJob = ['kind' => 'convert', 'actor_id' => (int) $staff->getKey(), 'request_id' => (int) $requestC->getKey()];
        $converted = $this->racePair('travel_tours', (int) $tourC->getKey(), $convertJob, $convertJob);
        $this->assertSuccessfulPair($converted, 'same request conversion');
        self::assertSame($converted[0]['id'] ?? null, $converted[1]['id'] ?? null);
        self::assertSame('converted', $requestC->fresh()?->status);
        self::assertSame(1, (int) $departureC->fresh()->seats_committed);
        self::assertSame(1, DB::table('travel_tour_bookings')
            ->where('reference_key', 'BKG-' . $requestC->reference_key)->count());
        self::assertSame(1, DB::table('travel_tour_seat_allocations')
            ->where('reference_key', 'BKG-' . $requestC->reference_key)->count());

        // D. Approval versus conversion: conversion may reject first, but cannot bypass approval.
        [$tourD, $departureD] = $this->raceTour($prefix . '-approve', 2);
        $receiptD = app(TourBookingRequestManager::class)->createCustomer(
            $customer,
            $tourD,
            $departureD,
            'standard-bdt',
            1,
            0
        );
        $requestD = $receiptD->request;
        $previewD = app(TourBookingQuoteAcceptanceManager::class)->previewCustomer($customer, $requestD);
        $approval = $this->racePair(
            'travel_tours',
            (int) $tourD->getKey(),
            [
                'kind' => 'accept_quote',
                'actor_id' => (int) $customer->getKey(),
                'request_id' => (int) $requestD->getKey(),
                'revision' => (int) $previewD['revision'],
                'fingerprint' => (string) $previewD['fingerprint'],
            ],
            ['kind' => 'convert', 'actor_id' => (int) $staff->getKey(), 'request_id' => (int) $requestD->getKey()]
        );
        self::assertTrue($approval[0]['ok'] === true, 'Traveler quote approval must complete.');
        self::assertTrue(
            $approval[1]['ok'] === true || $approval[1]['kind'] === 'validation',
            'Conversion must either follow approval or reject safely.'
        );
        $bookD = app(TourBookingRequestManager::class)->convertVerified($staff, $requestD);
        self::assertSame('converted', $requestD->fresh()?->status);
        self::assertSame(1, DB::table('travel_tour_bookings')->where('reference_key', 'BKG-' . $requestD->reference_key)->count());
        self::assertSame(1, (int) $departureD->fresh()->seats_committed);
        self::assertSame((int) $bookD->getKey(), (int) $requestD->fresh()?->booking_id);

        // E. Exact same offline evidence on two independent workers: one immutable ledger row.
        [$tourE, $departureE] = $this->raceTour($prefix . '-payment', 2);
        $bookingE = app(TourBookingLifecycleManager::class)->createInternal(
            $staff,
            $tourE,
            $departureE,
            'RACE-PAY-' . $prefix,
            'standard-bdt',
            1
        );
        $payJob = $this->reconcileJob($staff, $bookingE, 'RECEIPT-SAME-' . $prefix);
        $payments = $this->racePair('travel_tours', (int) $tourE->getKey(), $payJob, $payJob);
        $this->assertSuccessfulPair($payments, 'same offline evidence');
        self::assertSame($payments[0]['id'] ?? null, $payments[1]['id'] ?? null);
        self::assertSame('paid', $bookingE->fresh()?->payment_state);
        self::assertSame(1, DB::table('travel_tour_booking_offline_payments')
            ->where('booking_id', (int) $bookingE->getKey())->count());
        self::assertSame(1, (int) $departureE->fresh()->seats_committed);

        // F. Payment versus cancellation must serialize (never paid + cancelled/released).
        [$tourF, $departureF] = $this->raceTour($prefix . '-cancel', 2);
        $bookingF = app(TourBookingLifecycleManager::class)->createInternal(
            $staff,
            $tourF,
            $departureF,
            'RACE-CAN-' . $prefix,
            'standard-bdt',
            1
        );
        $cancel = $this->racePair(
            'travel_tours',
            (int) $tourF->getKey(),
            $this->reconcileJob($staff, $bookingF, 'RECEIPT-CANCEL-' . $prefix),
            ['kind' => 'cancel', 'actor_id' => (int) $staff->getKey(), 'booking_id' => (int) $bookingF->getKey()]
        );
        $this->assertOneSuccessOneValidation($cancel, 'payment versus cancellation');
        $freshF = $bookingF->fresh();
        self::assertNotNull($freshF);
        if ((string) $freshF->payment_state === 'paid') {
            self::assertNotSame('cancelled', $freshF->status);
            self::assertSame(1, (int) $departureF->fresh()->seats_committed);
            self::assertSame(1, DB::table('travel_tour_booking_offline_payments')
                ->where('booking_id', (int) $bookingF->getKey())->count());
        } else {
            self::assertSame('cancelled', $freshF->status);
            self::assertSame('not_collected', $freshF->payment_state);
            self::assertSame(0, (int) $departureF->fresh()->seats_committed);
            self::assertSame(0, DB::table('travel_tour_booking_offline_payments')
                ->where('booking_id', (int) $bookingF->getKey())->count());
        }

        // G. Expiry worker versus verified customer withdrawal: one terminal transition.
        [$tourG, $departureG] = $this->raceTour($prefix . '-expiry', 2);
        $receiptG = app(TourBookingRequestManager::class)->createCustomer(
            $customer,
            $tourG,
            $departureG,
            'standard-bdt',
            1,
            0
        );
        $requestG = $receiptG->request;
        $requestG->setAttribute('expires_at_utc', now('UTC')->subMinute()->format('Y-m-d H:i:s'));
        $requestG->save();
        $expiry = $this->racePair(
            'travel_tour_booking_requests',
            (int) $requestG->getKey(),
            ['kind' => 'expire_batch', 'actor_id' => (int) $staff->getKey()],
            ['kind' => 'withdraw_customer', 'actor_id' => (int) $customer->getKey(), 'request_id' => (int) $requestG->getKey()]
        );
        self::assertSame(true, $expiry[0]['ok']);
        self::assertTrue($expiry[1]['ok'] === true || $expiry[1]['kind'] === 'validation');
        self::assertContains((string) $requestG->fresh()?->status, ['expired', 'withdrawn']);
        self::assertNull($requestG->fresh()?->booking_id);
        self::assertNull($requestG->fresh()?->access_token_hash);
        self::assertSame(0, (int) $departureG->fresh()->seats_committed);

        // H. Confirmation versus overdue sweeper: no post-deadline confirmation or seat release.
        [$tourH, $departureH] = $this->raceTour($prefix . '-deadline', 2);
        $bookingH = app(TourBookingLifecycleManager::class)->createInternal(
            $staff,
            $tourH,
            $departureH,
            'RACE-DUE-' . $prefix,
            'standard-bdt',
            1
        );
        $bookingH->setAttribute('confirmation_due_at_utc', now('UTC')->subMinute()->format('Y-m-d H:i:s'));
        $bookingH->save();
        $deadlineRace = $this->racePair(
            'travel_tours',
            (int) $tourH->getKey(),
            ['kind' => 'flag_overdue', 'actor_id' => (int) $staff->getKey()],
            ['kind' => 'confirm', 'actor_id' => (int) $staff->getKey(), 'booking_id' => (int) $bookingH->getKey()]
        );
        self::assertSame(true, $deadlineRace[0]['ok']);
        self::assertSame(false, $deadlineRace[1]['ok']);
        self::assertSame('validation', $deadlineRace[1]['kind'] ?? '');
        self::assertSame('pending_confirmation', $bookingH->fresh()?->status);
        self::assertNotNull($bookingH->fresh()?->getRawOriginal('timeout_review_required_at_utc'));
        self::assertSame(1, (int) $departureH->fresh()->seats_committed);
        self::assertSame('not_collected', $bookingH->fresh()?->payment_state);
    }

    private function assertDedicatedDatabase(): void
    {
        self::assertTrue(app()->environment('testing'), 'APP_ENV must be testing.');
        $default = (string) config('database.default');
        $connection = config('database.connections.' . $default);
        self::assertIsArray($connection);
        self::assertSame('mysql', $connection['driver'] ?? null, 'Actual default test DB must be MySQL.');
        self::assertTrue(
            empty($connection['url']) && !isset($connection['read']) && !isset($connection['write']),
            'Use explicit DB_HOST / DB_DATABASE; connection URLs and replicas are forbidden for this QA.'
        );
        $configName = (string) ($connection['database'] ?? '');
        self::assertSame(
            1,
            preg_match('/^[a-zA-Z0-9_]+_race_test$/D', $configName),
            'Use an isolated database whose name ends in _race_test.'
        );
        $actual = DB::selectOne('SELECT DATABASE() AS db_name');
        self::assertIsObject($actual);
        self::assertSame(
            $configName,
            (string) ($actual->db_name ?? ''),
            'Actual selected MySQL database must match the dedicated configuration.'
        );
        // No test-trait transaction: independent workers must see the committed fixtures.
        self::assertSame(0, DB::transactionLevel(), 'The race test must not run inside RefreshDatabase.');
        $this->raceDatabase = $configName;
        $this->raceConnection = $connection;
    }

    private function raceStaff(): User
    {
        $staff = User::factory()->create(['email_verified_at' => now('UTC')]);
        CmsRole::query()->where('key', 'administrator')->firstOrFail()
            ->users()->attach($staff->getKey());
        return $staff;
    }

    /** @return array{0:Tour,1:TourDeparture} */
    private function raceTour(string $slug, int $capacity): array
    {
        $tour = Tour::query()->create([
            'title' => 'Race ' . $slug,
            'slug' => $slug,
            'status' => 'published',
            'published_at' => now('UTC')->subDay(),
            'duration_days' => 3,
            'duration_nights' => 2,
        ]);
        $category = TourCategory::query()->create([
            'name' => 'Category ' . $slug,
            'slug' => $slug . '-category',
            'status' => 'published',
        ]);
        $destination = Destination::query()->create([
            'name' => 'Destination ' . $slug,
            'slug' => $slug . '-destination',
            'country_code' => 'BD',
            'status' => 'published',
        ]);
        $tour->categories()->attach($category->getKey());
        $tour->destinations()->attach($destination->getKey());
        $variant = TourPriceVariant::query()->create([
            'tour_id' => (int) $tour->getKey(),
            'code' => 'standard-bdt',
            'name' => 'Standard BDT',
            'currency' => 'BDT',
            'pricing_model' => 'per_person',
            'min_travelers' => 1,
            'max_travelers' => 10,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $variant->rates()->create([
            'traveler_type' => 'adult',
            'regular_minor' => 1500000,
            'sale_minor' => null,
            'sale_starts_at' => null,
            'sale_ends_at' => null,
        ]);
        $variant->rates()->create([
            'traveler_type' => 'child',
            'regular_minor' => 800000,
            'sale_minor' => null,
            'sale_starts_at' => null,
            'sale_ends_at' => null,
        ]);
        $departure = TourDeparture::query()->create([
            'tour_id' => (int) $tour->getKey(),
            'code' => 'summer-a',
            'name' => 'Summer departure',
            'timezone_name' => 'Asia/Dhaka',
            'starts_at' => now('UTC')->addMonths(2)->format('Y-m-d H:i:s'),
            'ends_at' => now('UTC')->addMonths(2)->addDays(3)->format('Y-m-d H:i:s'),
            'booking_closes_at' => now('UTC')->addMonth()->format('Y-m-d H:i:s'),
            'capacity_total' => $capacity,
            'status' => 'open',
        ]);
        return [$tour, $departure];
    }

    private function approvedRequest(User $customer, Tour $tour, TourDeparture $departure): TourBookingRequest
    {
        $row = app(TourBookingRequestManager::class)->createCustomer(
            $customer,
            $tour,
            $departure,
            'standard-bdt',
            1,
            0
        )->request;
        $quotes = app(TourBookingQuoteAcceptanceManager::class);
        $version = $quotes->previewCustomer($customer, $row);
        $quotes->acceptCustomer($customer, $row, (int) $version['revision'], (string) $version['fingerprint']);
        return $row;
    }

    /** @return array<string, int|string> */
    private function allocateJob(User $actor, Tour $tour, TourDeparture $departure, string $reference): array
    {
        return [
            'kind' => 'allocate',
            'actor_id' => (int) $actor->getKey(),
            'tour_id' => (int) $tour->getKey(),
            'departure_id' => (int) $departure->getKey(),
            'reference' => $reference,
        ];
    }

    /** @return array<string, int|string> */
    private function reconcileJob(User $actor, TourBooking $booking, string $reference): array
    {
        return [
            'kind' => 'reconcile',
            'actor_id' => (int) $actor->getKey(),
            'booking_id' => (int) $booking->getKey(),
            'amount_minor' => (int) $booking->total_minor,
            'currency' => (string) $booking->currency,
            'evidence_reference' => $reference,
        ];
    }

    /**
     * @param array<string,int|string> $left
     * @param array<string,int|string> $right
     * @return array{0:array{ok:bool,id?:int,kind?:string},1:array{ok:bool,id?:int,kind?:string}}
     */
    private function racePair(string $lockTable, int $lockId, array $left, array $right): array
    {
        $markerDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bablys_race_' . bin2hex(random_bytes(8));
        if (!mkdir($markerDir, 0700, true) && !is_dir($markerDir)) {
            throw new RuntimeException('Cannot create race synchronization directory.');
        }
        /** @var list<Process> $workers */
        $workers = [];
        try {
            $lockHeld = false;
            try {
                DB::beginTransaction();
                $lockHeld = true;
                $row = DB::table($lockTable)->where('id', $lockId)->lockForUpdate()->first();
                self::assertNotNull($row, 'Missing lock target for race test.');

                foreach ([$left, $right] as $index => $job) {
                    $env = [
                        'APP_ENV' => 'testing',
                        'APP_KEY' => (string) config('app.key'),
                        'BABLYS_REAL_MYSQL_RACE' => '1',
                        'BABLYS_RACE_EXPECTED_DATABASE' => $this->raceDatabase,
                        'BABLYS_RACE_DB_CONFIG_BASE64' => base64_encode(json_encode($this->raceConnection, JSON_THROW_ON_ERROR)),
                        'BABLYS_RACE_JOB_BASE64' => base64_encode(json_encode($job, JSON_THROW_ON_ERROR)),
                        'BABLYS_RACE_MARKER_DIRECTORY' => $markerDir,
                        'BABLYS_RACE_SLOT' => (string) $index,
                    ];
                    $process = new Process([
                        PHP_BINARY,
                        base_path('tests/Support/Bablys/real_mysql_race_worker.php'),
                    ], base_path(), $env, null, 45);
                    $process->start();
                    $workers[] = $process;
                }

                $deadline = microtime(true) + 20;
                while (!is_file($markerDir . '/0.ready') || !is_file($markerDir . '/1.ready')) {
                    if (microtime(true) > $deadline) {
                        throw new RuntimeException('MySQL race workers did not become ready within 20 seconds.');
                    }
                    foreach ($workers as $process) {
                        if (!$process->isRunning()) {
                            throw new RuntimeException('Race worker exited before readiness: ' . trim($process->getOutput()));
                        }
                    }
                    usleep(20_000);
                }
                if (file_put_contents($markerDir . '/go', 'go', LOCK_EX) === false) {
                    throw new RuntimeException('Cannot synchronize workers.');
                }
                // Both separate workers are released toward the SAME held InnoDB row lock.
                usleep(300_000);
            } finally {
                if ($lockHeld) {
                    DB::rollBack(); // Release coordinator lock; never undo committed fixtures.
                }
            }

            /** @var list<array{ok:bool,id?:int,kind?:string}> $results */
            $results = [];
            foreach ($workers as $process) {
                $process->wait();
                $lines = preg_split('/\R/', trim($process->getOutput()));
                $lastLine = is_array($lines) ? end($lines) : false;
                $decoded = is_string($lastLine) ? json_decode($lastLine, true) : null;
                if (!is_array($decoded) || !array_key_exists('ok', $decoded) || !is_bool($decoded['ok'])) {
                    throw new RuntimeException('Worker returned malformed output; exit: ' . (string) $process->getExitCode());
                }
                if (!$process->isSuccessful()) {
                    throw new RuntimeException('Worker failed with ' . (string) ($decoded['kind'] ?? 'unknown'));
                }
                if ($decoded['ok'] === true && isset($decoded['id']) && is_int($decoded['id'])) {
                    $results[] = ['ok' => true, 'id' => $decoded['id']];
                } elseif ($decoded['ok'] === false && isset($decoded['kind']) && is_string($decoded['kind'])) {
                    $results[] = ['ok' => false, 'kind' => $decoded['kind']];
                } else {
                    throw new RuntimeException('Worker returned an invalid result shape.');
                }
            }
            if (count($results) !== 2) {
                throw new RuntimeException('Expected exactly two independent worker results.');
            }
            return [$results[0], $results[1]];
        } finally {
            foreach ($workers as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
            foreach (['0.ready', '1.ready', 'go'] as $file) {
                @unlink($markerDir . '/' . $file);
            }
            @rmdir($markerDir);
        }
    }

    /** @param array{0:array{ok:bool,id?:int,kind?:string},1:array{ok:bool,id?:int,kind?:string}} $results */
    private function assertSuccessfulPair(array $results, string $case): void
    {
        self::assertTrue($results[0]['ok'], $case . ' worker 1 must succeed.');
        self::assertTrue($results[1]['ok'], $case . ' worker 2 must succeed.');
        self::assertArrayHasKey('id', $results[0]);
        self::assertArrayHasKey('id', $results[1]);
    }

    /** @param array{0:array{ok:bool,id?:int,kind?:string},1:array{ok:bool,id?:int,kind?:string}} $results */
    private function assertOneSuccessOneValidation(array $results, string $case): void
    {
        $successes = ($results[0]['ok'] ? 1 : 0) + ($results[1]['ok'] ? 1 : 0);
        self::assertSame(1, $successes, $case . ' must have exactly one successful mutation.');
        foreach ($results as $result) {
            if (!$result['ok']) {
                self::assertSame('validation', $result['kind'] ?? '', $case . ' loser must reject cleanly.');
            }
        }
    }
}
