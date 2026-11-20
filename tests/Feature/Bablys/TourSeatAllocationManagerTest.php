<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Bookings\TourSeatAllocationManager;
use App\Models\Cms\CmsRole;
use App\Models\Travel\TourDeparture;
use App\Models\Travel\TourSeatAllocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Bablys\TourBookingQaTestCase;

final class TourSeatAllocationManagerTest extends TourBookingQaTestCase
{
    public function test_commits_adult_and_child_seats_atomically(): void
    {
        [$tour, $departure] = $this->readyTour('commit-seats');
        $allocation = $this->manager()->commit(
            $this->staff('administrator'), $tour, $departure, 'BOOK-commit-001', 'standard-bdt', 2, 1
        );
        self::assertSame(3, (int) $allocation->seats);
        self::assertSame('committed', $allocation->status);
        self::assertSame(3, (int) $departure->fresh()->seats_committed);
        self::assertDatabaseCount('travel_tour_seat_allocations', 1);
        self::assertSame(1, DB::table('cms_audit_events')->where('action', 'tour.seats.committed')->count());
    }

    public function test_identical_retry_returns_same_ledger_without_new_seats_or_audit(): void
    {
        [$tour, $departure] = $this->readyTour('idempotent-booking');
        $actor = $this->staff('administrator');
        $first = $this->manager()->commit($actor, $tour, $departure, 'BOOK-identical-001', 'standard-bdt', 2, 0);
        $second = $this->manager()->commit($actor, $tour, $departure, 'BOOK-identical-001', 'standard-bdt', 2, 0);
        self::assertSame($first->getKey(), $second->getKey());
        self::assertSame(2, (int) $departure->fresh()->seats_committed);
        self::assertSame(1, DB::table('cms_audit_events')->where('action', 'tour.seats.committed')->count());
    }

    public function test_same_reference_cannot_change_party_size_or_price_option(): void
    {
        [$tour, $departure] = $this->readyTour('ref-collision');
        $actor = $this->staff('administrator');
        $this->manager()->commit($actor, $tour, $departure, 'BOOK-collision-001', 'standard-bdt', 1, 0);
        try {
            $this->manager()->commit($actor, $tour, $departure, 'BOOK-collision-001', 'standard-bdt', 2, 0);
            self::fail('Changed idempotency payload must be rejected.');
        } catch (ValidationException) {
            self::assertSame(1, (int) $departure->fresh()->seats_committed);
            self::assertDatabaseCount('travel_tour_seat_allocations', 1);
        }
    }

    public function test_two_references_never_exceed_capacity(): void
    {
        [$tour, $departure] = $this->readyTour('no-oversell');
        $actor = $this->staff('administrator');
        $this->manager()->commit($actor, $tour, $departure, 'BOOK-fill-0001', 'standard-bdt', 8, 0);
        try {
            $this->manager()->commit($actor, $tour, $departure, 'BOOK-fill-0002', 'standard-bdt', 3, 0);
            self::fail('Over-capacity allocation must fail.');
        } catch (ValidationException) {
            self::assertSame(8, (int) $departure->fresh()->seats_committed);
            self::assertDatabaseCount('travel_tour_seat_allocations', 1);
            self::assertSame(1, DB::table('cms_audit_events')->where('action', 'tour.seats.committed')->count());
        }
    }

    public function test_releasing_seats_is_idempotent_and_audited_once(): void
    {
        [$tour, $departure] = $this->readyTour('release-once');
        $actor = $this->staff('administrator');
        $this->manager()->commit($actor, $tour, $departure, 'BOOK-release-0001', 'standard-bdt', 2, 1);
        self::assertTrue($this->manager()->release($actor, $tour, 'BOOK-release-0001'));
        self::assertFalse($this->manager()->release($actor, $tour, 'BOOK-release-0001'));
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        self::assertSame('released', TourSeatAllocation::query()->firstOrFail()->status);
        self::assertSame(1, DB::table('cms_audit_events')->where('action', 'tour.seats.released')->count());
    }

    public function test_released_booking_reference_cannot_be_recommitted(): void
    {
        [$tour, $departure] = $this->readyTour('reused-reference');
        $actor = $this->staff('administrator');
        $this->manager()->commit($actor, $tour, $departure, 'BOOK-used-0001', 'standard-bdt', 1, 0);
        $this->manager()->release($actor, $tour, 'BOOK-used-0001');
        $this->expectException(ValidationException::class);
        $this->manager()->commit($actor, $tour, $departure, 'BOOK-used-0001', 'standard-bdt', 1, 0);
    }

    public function test_a_released_reference_stays_reserved_after_departure_deleted(): void
    {
        [$tour, $departure] = $this->readyTour('released-departure-deleted');
        $actor = $this->staff('administrator');
        $this->manager()->commit($actor, $tour, $departure, 'BOOK-gone-0001', 'standard-bdt', 1, 0);
        $this->manager()->release($actor, $tour, 'BOOK-gone-0001');
        $departure->delete();
        self::assertFalse($this->manager()->release($actor, $tour, 'BOOK-gone-0001'));
        self::assertDatabaseHas('travel_tour_seat_allocations', [
            'reference_key' => 'BOOK-gone-0001', 'departure_id' => null, 'status' => 'released',
        ]);
    }

    public function test_closing_a_departure_does_not_prevent_releasing_existing_seats(): void
    {
        [$tour, $departure] = $this->readyTour('closed-release');
        $actor = $this->staff('administrator');
        $this->manager()->commit($actor, $tour, $departure, 'BOOK-closed-0001', 'standard-bdt', 1, 0);
        TourDeparture::query()->whereKey($departure->getKey())->update(['status' => 'closed']);
        self::assertTrue($this->manager()->release($actor, $tour, 'BOOK-closed-0001'));
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
    }

    public function test_idempotent_retry_succeeds_after_cutoff_without_recounting_seats(): void
    {
        [$tour, $departure] = $this->readyTour('late-retry');
        $actor = $this->staff('administrator');
        $first = $this->manager()->commit($actor, $tour, $departure, 'BOOK-late-0001', 'standard-bdt', 1, 0);
        TourDeparture::query()->whereKey($departure->getKey())->update([
            'booking_closes_at' => now('UTC')->subDay()->format('Y-m-d H:i:s'),
        ]);
        $same = $this->manager()->commit($actor, $tour, $departure, 'BOOK-late-0001', 'standard-bdt', 1, 0);
        self::assertSame($first->getKey(), $same->getKey());
        self::assertSame(1, (int) $departure->fresh()->seats_committed);
    }

    public function test_draft_tour_rejected_without_counter_or_ledger_change(): void
    {
        [$tour, $departure] = $this->readyTour('draft-denial');
        $tour->status = 'draft';
        $tour->save();
        try {
            $this->manager()->commit($this->staff('administrator'), $tour, $departure, 'BOOK-draft-0001', 'standard-bdt', 1);
            self::fail('Draft tour allocation must fail.');
        } catch (ValidationException) {
            self::assertDatabaseCount('travel_tour_seat_allocations', 0);
            self::assertSame(0, (int) $departure->fresh()->seats_committed);
        }
    }

    public function test_departure_from_another_tour_rejected(): void
    {
        [$tour] = $this->readyTour('other-tour-one');
        [, $otherDeparture] = $this->readyTour('other-tour-two');
        $this->expectException(ValidationException::class);
        $this->manager()->commit($this->staff('administrator'), $tour, $otherDeparture,
            'BOOK-cross-0001', 'standard-bdt', 1);
    }

    public function test_nonexistent_price_variant_rejected(): void
    {
        [$tour, $departure] = $this->readyTour('missing-price-commit');
        $this->expectException(ValidationException::class);
        $this->manager()->commit($this->staff('administrator'), $tour, $departure,
            'BOOK-price-0001', 'missing-price', 1);
    }

    public function test_content_editor_cannot_commit_or_release_seats(): void
    {
        [$tour, $departure] = $this->readyTour('staff-permissions');
        $editor = $this->staff('content_editor');
        try {
            $this->manager()->commit($editor, $tour, $departure, 'BOOK-editor-0001', 'standard-bdt', 1);
            self::fail('Editor must not commit seats.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
            self::assertSame(0, (int) $departure->fresh()->seats_committed);
        }
    }

    public function test_role_revocation_blocks_next_booking_write(): void
    {
        [$tour, $departure] = $this->readyTour('role-revoked');
        $actor = $this->staff('administrator');
        CmsRole::query()->where('key', 'administrator')->firstOrFail()->users()->detach($actor->getKey());
        $this->expectException(HttpException::class);
        $this->manager()->commit($actor, $tour, $departure, 'BOOK-revoke-0001', 'standard-bdt', 1);
    }

    public function test_global_reference_cannot_be_reused_for_another_tour(): void
    {
        [$tour, $departure] = $this->readyTour('ref-other-one');
        [$other, $otherDeparture] = $this->readyTour('ref-other-two');
        $actor = $this->staff('administrator');
        $this->manager()->commit($actor, $tour, $departure, 'BOOK-global-0001', 'standard-bdt', 1);
        $this->expectException(ValidationException::class);
        $this->manager()->commit($actor, $other, $otherDeparture, 'BOOK-global-0001', 'standard-bdt', 1);
    }

    public function test_bad_reference_is_rejected_without_mutation(): void
    {
        [$tour, $departure] = $this->readyTour('bad-ref');
        $this->expectException(ValidationException::class);
        $this->manager()->commit($this->staff('administrator'), $tour, $departure, 'x', 'standard-bdt', 1);
    }

    public function test_audit_never_includes_booking_reference_or_price(): void
    {
        [$tour, $departure] = $this->readyTour('audit-redacted');
        $actor = $this->staff('administrator');
        $this->manager()->commit($actor, $tour, $departure, 'BOOK-private-0001', 'standard-bdt', 1);
        $this->manager()->release($actor, $tour, 'BOOK-private-0001');
        $audit = DB::table('cms_audit_events')->whereIn('action', [
            'tour.seats.committed', 'tour.seats.released',
        ])->get();
        self::assertCount(2, $audit);
        $encoded = json_encode($audit);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('BOOK-private-0001', $encoded);
        self::assertStringNotContainsString('1500000', $encoded);
    }

    public function test_unknown_release_reference_does_not_change_inventory(): void
    {
        [$tour, $departure] = $this->readyTour('release-missing');
        try {
            $this->manager()->release($this->staff('administrator'), $tour, 'BOOK-unknown-001');
            self::fail('Unknown release reference must fail.');
        } catch (ValidationException) {
            self::assertSame(0, (int) $departure->fresh()->seats_committed);
        }
    }

    private function manager(): TourSeatAllocationManager
    {
        return app(TourSeatAllocationManager::class);
    }
}
