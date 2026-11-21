<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account\Travel;

use App\Domain\Travel\Bookings\TourBookingRequestPresentation;
use App\Http\Controllers\Controller;
use App\Models\Travel\TourBooking;
use App\Models\Travel\TourBookingRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * P3H.4: verified customer-only read endpoints. Staff-created and guest-only
 * bookings are NOT customer-owned merely because a contact email matches.
 * No model is serialized; guest tokens, contact ciphertext, and payment proof
 * never enter Inertia props. Existing owner-only quote endpoints are unchanged.
 */
final class CustomerTravelAccountController extends Controller
{
    public function index(Request $request, TourBookingRequestPresentation $presenter): Response
    {
        $customer = $this->customer($request);
        $ownerId = (int) $customer->getKey();
        $requests = $presenter->customerRows($customer);
        $bookings = $this->ownedBookings($customer)->orderByDesc('id')->limit(5)->get()
            ->map(fn (TourBooking $booking): array => $this->summary($booking))->values()->all();

        return $this->privatePage($request, 'account/travel/index', [
            'summary' => [
                'requests' => TourBookingRequest::query()->where('customer_id', $ownerId)->count(),
                'bookings' => $this->ownedBookings($customer)->count(),
                'confirmed' => $this->ownedBookings($customer)->where('status', 'confirmed')->count(),
                'pending' => $this->ownedBookings($customer)->where('status', 'pending_confirmation')->count(),
            ],
            'recent_requests' => array_slice($requests, 0, 5),
            'recent_bookings' => $bookings,
        ]);
    }

    public function bookings(Request $request): Response
    {
        $customer = $this->customer($request);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:100000']]);
        $page = $this->ownedBookings($customer)->orderByDesc('id')->paginate(15);

        return $this->privatePage($request, 'account/bookings/index', [
            'bookings' => $page->getCollection()->map(
                fn (TourBooking $booking): array => $this->summary($booking)
            )->values()->all(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(Request $request, string $reference): Response
    {
        $customer = $this->customer($request);
        // Reject malformed references and avoid providing an existence oracle.
        abort_unless(preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,99}$/D', $reference) === 1, 404);
        $booking = $this->ownedBookings($customer)->where('reference_key', $reference)->firstOrFail();
        $requestRow = TourBookingRequest::query()
            ->where('booking_id', (int) $booking->getKey())
            ->where('customer_id', (int) $customer->getKey())
            ->firstOrFail();

        return $this->privatePage($request, 'account/bookings/show', [
            'booking' => array_merge($this->summary($booking), [
                'regular_total_minor' => (int) $booking->regular_total_minor,
                'discount_minor' => (int) $booking->discount_minor,
                'variant_code' => (string) $booking->variant_code,
                'departure_timezone' => (string) $booking->departure_timezone,
                'confirmed_at_utc' => $this->utcTimestamp($booking, 'confirmed_at'),
                'cancelled_at_utc' => $this->utcTimestamp($booking, 'cancelled_at'),
                'quoted_at_utc' => $this->utcTimestamp($booking, 'quoted_at_utc'),
            ]),
            'request_reference' => (string) $requestRow->reference_key,
        ]);
    }

    private function customer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->email_verified_at !== null, 403);

        return $user;
    }

    /** @return Builder<TourBooking> */
    private function ownedBookings(User $customer): Builder
    {
        $requestBookingIds = DB::table('travel_tour_booking_requests')
            ->select('booking_id')
            ->where('customer_id', (int) $customer->getKey())
            ->whereNotNull('booking_id');

        return TourBooking::query()->whereIn('id', $requestBookingIds);
    }

    /** @return array<string, string|int|null> */
    private function summary(TourBooking $booking): array
    {
        return [
            'reference' => (string) $booking->reference_key,
            'status' => (string) $booking->status,
            'payment_state' => (string) $booking->payment_state,
            'tour_title' => (string) $booking->tour_title_snapshot,
            'departure_at_utc' => $this->utcTimestamp($booking, 'departure_starts_at_utc'),
            'seats' => (int) $booking->seats,
            'adults' => (int) $booking->adults,
            'children' => (int) $booking->children,
            'currency' => (string) $booking->currency,
            'fraction_digits' => (int) $booking->fraction_digits,
            'total_minor' => (int) $booking->total_minor,
            'created_at_utc' => $this->utcTimestamp($booking, 'created_at'),
        ];
    }

    /** Storage-backed UTC SQL datetime; no reliance on model dynamic date PHPDocs.
     * @return string|null
     */
    private function utcTimestamp(TourBooking $booking, string $column): ?string
    {
        $raw = $booking->getRawOriginal($column);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return CarbonImmutable::parse($raw, 'UTC')->toIso8601String();
    }

    /** @param array<string,mixed> $props */
    private function privatePage(Request $request, string $view, array $props): Response
    {
        $response = Inertia::render($view, $props)->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
