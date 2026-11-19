<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Cms\Audit\CmsAuditFilters;
use App\Domain\Cms\Audit\CmsAuditReader;
use App\Domain\Cms\Audit\CmsAuditRecorder;
use App\Models\Cms\CmsRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\BablysCmsRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/** P2B.5D: dataset-scale QA with query-count guards (not flaky elapsed-time assertions). */
final class CmsAuditPerformanceFiltersQaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BablysCmsRbacSeeder::class);
    }

    public function test_large_history_paginates_without_returning_all_rows(): void
    {
        $owner = $this->owner();
        $this->insertEvents(225, (int) $owner->id);

        $this->actingAs($owner)->getJson('/admin/internal/audit-logs?per_page=25&page=1')
            ->assertOk()
            ->assertJsonCount(25, 'data')
            ->assertJsonPath('meta.total', 225)
            ->assertJsonPath('meta.last_page', 9)
            ->assertJsonMissingPath('data.0.metadata');
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs?per_page=25&page=9')
            ->assertOk()->assertJsonCount(25, 'data');
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs?per_page=25&page=10')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_reader_does_not_execute_one_query_per_audit_row(): void
    {
        $owner = $this->owner();
        $this->insertEvents(320, (int) $owner->id);
        $filters = new CmsAuditFilters(null, null, null, null, null, null, null, 1, 50);
        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        try {
            $result = app(CmsAuditReader::class)->paginate($filters);
            $queryCount = count($connection->getQueryLog());
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        self::assertCount(50, $result['data']);
        self::assertSame(320, $result['meta']['total']);
        self::assertLessThanOrEqual(3, $queryCount, 'Audit feed should use bounded queries, not one actor lookup per row.');
    }

    public function test_audit_indexes_support_common_actor_action_and_subject_filters(): void
    {
        $names = array_map(
            static fn (array $index): string => (string) ($index['name'] ?? ''),
            Schema::getIndexes('cms_audit_events')
        );
        self::assertContains('cms_audit_actor_time_idx', $names);
        self::assertContains('cms_audit_action_time_idx', $names);
        self::assertContains('cms_audit_subject_time_idx', $names);
    }

    public function test_rejects_invalid_filter_types_and_out_of_range_page_sizes(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs?search[]=no')
            ->assertUnprocessable()->assertJsonValidationErrors('search');
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs?actor_id=nope')
            ->assertUnprocessable()->assertJsonValidationErrors('actor_id');
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs?page=10001')
            ->assertUnprocessable()->assertJsonValidationErrors('page');
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs?per_page=500')
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs?date_from=2026-11-20&date_to=2026-11-19')
            ->assertUnprocessable()->assertJsonValidationErrors('date_to');
    }

    public function test_search_payload_is_parameterized_and_cannot_change_query_structure(): void
    {
        $owner = $this->owner();
        app(CmsAuditRecorder::class)->record('staff.created', $owner, 'staff_user', 100, []);
        $payload = rawurlencode("' OR 1=1 --");
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs?search='.$payload)
            ->assertOk()->assertJsonCount(0, 'data');
        self::assertSame(1, (int) DB::table('cms_audit_events')->count());
    }

    public function test_date_to_only_filter_keeps_end_date_inclusive_in_utc(): void
    {
        $owner = $this->owner();
        $this->travelTo(CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC'));
        app(CmsAuditRecorder::class)->record('staff.suspended', $owner, 'staff_user', 10);
        $this->travelTo(CarbonImmutable::parse('2026-11-19 23:30:00', 'UTC'));
        app(CmsAuditRecorder::class)->record('staff.restored', $owner, 'staff_user', 10);
        $this->travelBack();

        $this->actingAs($owner)->getJson('/admin/internal/audit-logs?date_to=2026-11-17')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'staff.suspended');
    }

    public function test_equal_timestamps_use_uuid_as_deterministic_tie_break(): void
    {
        $owner = $this->owner();
        $timestamp = '2026-11-19 00:00:00';
        $rows = [];
        foreach ([1, 2, 3, 4] as $i) {
            $rows[] = [
                'id' => sprintf('00000000-0000-4000-8000-%012d', $i),
                'actor_id' => $owner->id,
                'action' => 'staff.created',
                'subject_type' => 'staff_user',
                'subject_id' => (string) $i,
                'metadata_json' => '{}',
                'ip_address' => null,
                'user_agent' => null,
                'occurred_at' => $timestamp,
            ];
        }
        DB::table('cms_audit_events')->insert($rows);
        $response = $this->actingAs($owner)->getJson('/admin/internal/audit-logs?per_page=10')
            ->assertOk()->assertJsonCount(4, 'data');
        self::assertSame(
            array_reverse(array_column($rows, 'id')),
            array_column($response->json('data'), 'id')
        );
    }

    public function test_filtered_options_are_distinct_and_exclude_metadata(): void
    {
        $owner = $this->owner();
        $recorder = app(CmsAuditRecorder::class);
        $recorder->record('staff.created', $owner, 'staff_user', 1, ['password' => 'hidden1']);
        $recorder->record('staff.created', $owner, 'staff_user', 2, ['password' => 'hidden2']);
        $recorder->record('role.created', $owner, 'cms_role', 3, ['password' => 'hidden3']);
        $this->actingAs($owner)->getJson('/admin/internal/audit-logs/options')
            ->assertOk()
            ->assertJsonPath('data.actions', ['role.created', 'staff.created'])
            ->assertJsonPath('data.subject_types', ['cms_role', 'staff_user'])
            ->assertDontSee('hidden1')
            ->assertDontSee('hidden2');
    }

    private function insertEvents(int $count, int $actorId): void
    {
        $time = now('UTC');
        $batch = [];
        for ($i = 1; $i <= $count; $i++) {
            $batch[] = [
                'id' => (string) Str::uuid(),
                'actor_id' => $actorId,
                'action' => 'staff.created',
                'subject_type' => 'staff_user',
                'subject_id' => (string) $i,
                'metadata_json' => '{}',
                'ip_address' => null,
                'user_agent' => null,
                'occurred_at' => $time,
            ];
            if (count($batch) === 40) {
                DB::table('cms_audit_events')->insert($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            DB::table('cms_audit_events')->insert($batch);
        }
    }

    private function owner(): User
    {
        $user = User::factory()->create();
        $role = CmsRole::query()->where('key', 'super_admin')->firstOrFail();
        $role->users()->attach($user->getKey());
        return $user;
    }
}
