<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Bookings\TourBookingLifecycleManager;
use App\Domain\Travel\Bookings\TourSeatAllocationManager;
use App\Models\Cms\CmsRole;
use App\Models\Travel\TourBooking;
use App\Models\Travel\TourSeatAllocation;
use App\Models\Travel\TourPriceVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Bablys\TourBookingQaTestCase;

final class TourBookingLifecycleTest extends TourBookingQaTestCase
{
    private function manager(): TourBookingLifecycleManager
    {
        return app(TourBookingLifecycleManager::class);
    }

    public function test_internal_create_commits_exact_seats_and_snapshots_server_quote(): void
    {
        [$tour, $departure] = $this->readyTour('booking-internal');
        $booking = $this->manager()->createInternal(
            $this->staff('administrator'), $tour, $departure, 'BOOK-internal-0001', 'standard-bdt', 2, 1
        );
        self::assertSame('pending_confirmation', $booking->status);
        self::assertSame('not_collected', $booking->payment_state);
        self::assertSame('BDT', $booking->currency);
        self::assertSame(2, $booking->fraction_digits);
        self::assertSame(3800000, $booking->total_minor);
        self::assertSame(3, $booking->seats);
        self::assertCount(2, $booking->price_lines_snapshot);
        self::assertSame(3, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseHas('travel_tour_seat_allocations', [
            'reference_key' => 'BOOK-internal-0001', 'status' => 'committed',
        ]);
    }

    public function test_identical_retry_retains_booking_and_allocation_ids_and_no_extra_audit(): void
    {
        [$tour, $departure] = $this->readyTour('booking-retry');
        $actor = $this->staff('administrator');
        $one = $this->manager()->createInternal($actor, $tour, $departure, 'BOOK-repeat-0001', 'standard-bdt', 1);
        $two = $this->manager()->createInternal($actor, $tour, $departure, 'BOOK-repeat-0001', 'standard-bdt', 1);
        self::assertSame($one->id, $two->id);
        self::assertSame($one->seat_allocation_id, $two->seat_allocation_id);
        self::assertSame(1, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseCount('travel_tour_bookings', 1);
        self::assertSame(1, DB::table('cms_audit_events')->where('action', 'tour.booking.created')->count());
    }

    public function test_duplicate_reference_with_changed_party_is_rejected_without_new_seats(): void
    {
        [$tour, $departure] = $this->readyTour('booking-conflict');
        $actor = $this->staff('administrator');
        $this->manager()->createInternal($actor, $tour, $departure, 'BOOK-conflict-001', 'standard-bdt', 1);
        try {
            $this->manager()->createInternal($actor, $tour, $departure, 'BOOK-conflict-001', 'standard-bdt', 2);
            self::fail('Expected reference conflict.');
        } catch (ValidationException) {
            self::assertSame(1, (int) $departure->fresh()->seats_committed);
            $this->assertDatabaseCount('travel_tour_bookings', 1);
        }
    }

    public function test_same_reference_cannot_be_used_on_another_tour(): void
    {
        [$tour, $departure] = $this->readyTour('booking-cross-one');
        [$other, $otherDeparture] = $this->readyTour('booking-cross-two');
        $actor = $this->staff('administrator');
        $this->manager()->createInternal($actor, $tour, $departure, 'BOOK-global-book1', 'standard-bdt', 1);
        $this->expectException(ValidationException::class);
        $this->manager()->createInternal($actor, $other, $otherDeparture, 'BOOK-global-book1', 'standard-bdt', 1);
    }

    public function test_confirmation_is_idempotent_and_does_not_collect_payment(): void
    {
        [$tour, $departure] = $this->readyTour('booking-confirm');
        $actor = $this->staff('administrator');
        $booking = $this->manager()->createInternal($actor, $tour, $departure, 'BOOK-confirm-001', 'standard-bdt', 2);
        $this->manager()->confirm($actor, $booking);
        $this->manager()->confirm($actor, $booking);
        $booking->refresh();
        self::assertSame('confirmed', $booking->status);
        self::assertSame('not_collected', $booking->payment_state);
        self::assertNotNull($booking->confirmed_at);
        self::assertSame(2, (int) $departure->fresh()->seats_committed);
        self::assertSame(1, DB::table('cms_audit_events')->where('action', 'tour.booking.confirmed')->count());
    }

    public function test_cancel_releases_exact_inventory_once_even_after_confirmation(): void
    {
        [$tour, $departure] = $this->readyTour('booking-cancel');
        $actor = $this->staff('administrator');
        $booking = $this->manager()->createInternal($actor, $tour, $departure, 'BOOK-cancel-0001', 'standard-bdt', 2, 1);
        $this->manager()->confirm($actor, $booking);
        $this->manager()->cancel($actor, $booking);
        $this->manager()->cancel($actor, $booking);
        self::assertSame('cancelled', $booking->fresh()->status);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        self::assertSame('released', TourSeatAllocation::query()->firstOrFail()->status);
        self::assertSame(1, DB::table('cms_audit_events')->where('action', 'tour.booking.cancelled')->count());
    }

    public function test_cancelled_reference_cannot_be_reused_or_reconfirmed(): void
    {
        [$tour, $departure] = $this->readyTour('booking-tombstone');
        $actor = $this->staff('administrator');
        $booking = $this->manager()->createInternal($actor, $tour, $departure, 'BOOK-tombstone-01', 'standard-bdt', 1);
        $this->manager()->cancel($actor, $booking);
        try {
            $this->manager()->createInternal($actor, $tour, $departure, 'BOOK-tombstone-01', 'standard-bdt', 1);
            self::fail('Cancelled reference must be rejected.');
        } catch (ValidationException) {
            self::assertSame(0, (int) $departure->fresh()->seats_committed);
        }
        $this->expectException(ValidationException::class);
        $this->manager()->confirm($actor, $booking);
    }

    public function test_cancel_even_when_cutoff_has_passed_and_departure_closed(): void
    {
        [$tour, $departure] = $this->readyTour('booking-close');
        $actor = $this->staff('administrator');
        $booking = $this->manager()->createInternal($actor, $tour, $departure, 'BOOK-closed-0001', 'standard-bdt', 1);
        $departure->status = 'closed';
        $departure->booking_closes_at = now('UTC')->subMinute()->format('Y-m-d H:i:s');
        $departure->save();
        $this->manager()->cancel($actor, $booking);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
    }

    public function test_changed_price_does_not_rewrite_historic_snapshot(): void
    {
        [$tour, $departure] = $this->readyTour('booking-snapshot');
        $actor = $this->staff('administrator');
        $booking = $this->manager()->createInternal($actor, $tour, $departure, 'BOOK-snapshot-01', 'standard-bdt', 1);
        $old = $booking->total_minor;
        $variant = TourPriceVariant::query()->where('tour_id', $tour->getKey())
            ->where('code', 'standard-bdt')->firstOrFail();
        $variant->rates()->where('traveler_type', 'adult')->update(['regular_minor' => 2000000]);
        self::assertSame($old, $booking->fresh()->total_minor);
        self::assertSame(1500000, $booking->fresh()->price_lines_snapshot[0]['line_total_minor']);
    }

    public function test_insufficient_capacity_blocks_entire_booking(): void
    {
        [$tour, $departure] = $this->readyTour('booking-full');
        $departure->capacity_total = 1;
        $departure->save();
        $this->expectException(ValidationException::class);
        try {
            $this->manager()->createInternal($this->staff('administrator'), $tour, $departure, 'BOOK-full-000001', 'standard-bdt', 2);
        } finally {
            $this->assertDatabaseCount('travel_tour_bookings', 0);
            $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
        }
    }

    public function test_customer_cannot_create_staff_booking_or_cancel_it(): void
    {
        [$tour, $departure] = $this->readyTour('booking-auth');
        $actor = $this->staff('administrator');
        $booking = $this->manager()->createInternal($actor, $tour, $departure, 'BOOK-auth-000001', 'standard-bdt', 1);
        $customer = \App\Models\User::factory()->create();
        try {
            $this->manager()->cancel($customer, $booking);
            self::fail('Customer must be blocked.');
        } catch (HttpException $e) {
            self::assertSame(403, $e->getStatusCode());
        }
        self::assertSame(1, (int) $departure->fresh()->seats_committed);
        $this->assertDatabaseHas('travel_tour_bookings', ['reference_key' => 'BOOK-auth-000001', 'status' => 'pending_confirmation']);
    }

    public function test_revoked_staff_permissions_block_confirmation_and_cancel(): void
    {
        [$tour, $departure] = $this->readyTour('booking-revoke');
        $actor = $this->staff('administrator');
        $booking = $this->manager()->createInternal($actor, $tour, $departure, 'BOOK-revoke-0001', 'standard-bdt', 1);
        CmsRole::query()->where('key', 'administrator')->firstOrFail()->users()->detach($actor->getKey());
        $this->expectException(HttpException::class);
        $this->manager()->confirm($actor, $booking);
    }

    public function test_audit_does_not_reveal_reference_amounts_or_price_lines(): void
    {
        [$tour, $departure] = $this->readyTour('booking-audit');
        $actor = $this->staff('administrator');
        $booking = $this->manager()->createInternal($actor, $tour, $departure, 'BOOK-secret-0001', 'standard-bdt', 1);
        $this->manager()->confirm($actor, $booking);
        $this->manager()->cancel($actor, $booking);
        $events = DB::table('cms_audit_events')->whereIn('action', [
            'tour.booking.created', 'tour.booking.confirmed', 'tour.booking.cancelled',
        ])->get();
        self::assertCount(3, $events);
        $json = (string) json_encode($events);
        self::assertStringNotContainsString('BOOK-secret-0001', $json);
        self::assertStringNotContainsString('1500000', $json);
        self::assertStringNotContainsString('standard-bdt', $json);
    }

    public function test_existing_standalone_seat_allocation_cannot_be_claimed_as_booking(): void
    {
        [$tour, $departure] = $this->readyTour('booking-standalone');
        $actor = $this->staff('administrator');
        app(TourSeatAllocationManager::class)->commit(
            $actor, $tour, $departure, 'BOOK-standalone-01', 'standard-bdt', 1
        );
        try {
            $this->manager()->createInternal(
                $actor, $tour, $departure, 'BOOK-standalone-01', 'standard-bdt', 1
            );
            self::fail('A standalone allocation cannot be claimed by a booking.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('travel_tour_bookings', 0);
            self::assertSame(1, (int) $departure->fresh()->seats_committed);
        }
    }

    public function test_invalid_reference_does_not_allocate_seats(): void
    {
        [$tour, $departure] = $this->readyTour('booking-invalid');
        $this->expectException(ValidationException::class);
        try {
            $this->manager()->createInternal($this->staff('administrator'), $tour, $departure, 'bad', 'standard-bdt', 1);
        } finally {
            $this->assertDatabaseCount('travel_tour_bookings', 0);
            self::assertSame(0, (int) $departure->fresh()->seats_committed);
        }
    }
}
