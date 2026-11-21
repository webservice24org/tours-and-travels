<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Travel;

use App\Domain\Travel\Bookings\TourBookingLifecycleManager;
use App\Domain\Travel\Bookings\TourBookingRequestManager;
use App\Domain\Travel\Bookings\TourBookingTimeoutPolicy;
use App\Http\Controllers\Controller;
use App\Models\Travel\TourBooking;
use App\Models\Travel\TourBookingRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * P3H.2: action adapter only. Price approval, seat locks, audit and status
 * transitions remain solely in the existing booking domain services.
 * Never accept a client-supplied status, total, allocation or payment marker.
 */
final class AdminBookingActionsController extends Controller
{
    public function confirm(
        Request $request,
        string $reference,
        TourBookingLifecycleManager $lifecycle,
    ): RedirectResponse {
        $actor = $this->authorizeActor($request);
        $booking = $this->booking($reference);
        $confirmed = $lifecycle->confirm($actor, $booking);

        return $this->redirectToBooking((string) $confirmed->reference_key, 'Booking confirmed. No payment was collected or seats reserved twice.');
    }

    public function cancel(
        Request $request,
        string $reference,
        TourBookingLifecycleManager $lifecycle,
        TourBookingTimeoutPolicy $timeoutPolicy,
    ): RedirectResponse {
        $actor = $this->authorizeActor($request);
        $booking = $this->booking($reference);
        // Defense in depth; authoritative domain cancellation also checks under
        // row lock, so this read is never used to authorize release of inventory.
        $timeoutPolicy->assertCancellationAllowed($booking);
        $cancelled = $lifecycle->cancel($actor, $booking);

        return $this->redirectToBooking((string) $cancelled->reference_key, 'Booking cancelled; committed seats released once. Refunds are handled separately.');
    }

    public function convert(
        Request $request,
        string $reference,
        TourBookingRequestManager $requests,
    ): RedirectResponse {
        $actor = $this->authorizeActor($request);
        abort_unless(preg_match('/\ABRQ-[a-f0-9]{32}\z/D', $reference) === 1, 404);

        $bookingRequest = TourBookingRequest::query()->where('reference_key', $reference)->firstOrFail();
        // convertVerified() validates verification + expiry + current owner-
        // accepted quote, then atomically commits allocation and booking.
        $booking = $requests->convertVerified($actor, $bookingRequest);

        return $this->redirectToBooking((string) $booking->reference_key, 'Approved request converted. Booking awaits confirmation; payment was not collected.');
    }

    private function authorizeActor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        Gate::forUser($actor)->authorize('tours.edit');
        Gate::forUser($actor)->authorize('tours.publish');

        return $actor;
    }

    private function booking(string $reference): TourBooking
    {
        abort_unless(preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{7,99}\z/D', $reference) === 1, 404);
        return TourBooking::query()->where('reference_key', $reference)->firstOrFail();
    }

    private function redirectToBooking(string $reference, string $message): RedirectResponse
    {
        $response = to_route('admin.bookings.show', ['reference' => $reference])->with('success', $message);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
