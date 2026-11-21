<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Travel;

use App\Domain\Travel\Bookings\TourBookingRequestPresentation;
use Carbon\CarbonImmutable;
use App\Http\Controllers\Controller;
use App\Models\Travel\TourBooking;
use App\Models\Travel\TourBookingRequest;
use App\Models\Travel\TourDeparture;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/** P3H.1: staff-only, read-only booking dashboards; mutation UI is a later phase. */
final class AdminBookingsPageController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeStaff();

        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', 'in:pending_confirmation,confirmed,cancelled'],
            'payment_state' => ['sometimes', 'nullable', 'in:not_collected,paid'],
            'q' => ['sometimes', 'nullable', 'string', 'max:80'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
        ]);
        $status = (string) ($validated['status'] ?? '');
        $paymentState = (string) ($validated['payment_state'] ?? '');
        $search = trim((string) ($validated['q'] ?? ''));

        $query = TourBooking::query();
        if ($status !== '') {
            $query->where('status', $status);
        }
        if ($paymentState !== '') {
            $query->where('payment_state', $paymentState);
        }
        if ($search !== '') {
            // Escape LIKE metacharacters: a search term is literal, never a query operator.
            $literal = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where(static function (Builder $rows) use ($literal): void {
                $rows->where('reference_key', 'like', '%' . $literal . '%')
                    ->orWhere('tour_title_snapshot', 'like', '%' . $literal . '%');
            });
        }

        $page = $query->orderByDesc('id')->paginate(20)->withQueryString();
        $counts = TourBooking::query()->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')->pluck('aggregate', 'status');
        $confirmedSeats = (int) TourBooking::query()->where('status', 'confirmed')->sum('seats');
        $pendingSeats = (int) TourBooking::query()->where('status', 'pending_confirmation')->sum('seats');

        $response = Inertia::render('admin/bookings/index', [
            'bookings' => $page->getCollection()->map(fn(TourBooking $booking): array => $this->summary($booking))->values()->all(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'filters' => ['status' => $status, 'payment_state' => $paymentState, 'q' => $search],
            'metrics' => [
                'total_bookings' => (int) $counts->sum(),
                'confirmed_bookings' => (int) ($counts['confirmed'] ?? 0),
                'pending_bookings' => (int) ($counts['pending_confirmation'] ?? 0),
                'cancelled_bookings' => (int) ($counts['cancelled'] ?? 0),
                'confirmed_seats' => $confirmedSeats,
                'pending_seats' => $pendingSeats,
            ],
            'read_only' => true,
        ])->toResponse($request);

        return $this->privateResponse($response);
    }

    public function show(Request $request, string $reference, TourBookingRequestPresentation $presenter): Response
    {
        $this->authorizeStaff();
        abort_unless(preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,99}$/D', $reference) === 1, 404);

        $booking = TourBooking::query()->where('reference_key', $reference)->firstOrFail();
        $relatedRequest = TourBookingRequest::query()->with(['tour', 'departure'])
            ->where('booking_id', $booking->getKey())->first();
        // This decrypted contact is restricted to the same two-permission senior-staff gate.
        $contact = $relatedRequest === null ? null : $presenter->staff($relatedRequest);
        $departure = $booking->departure_id === null ? null
            : TourDeparture::query()->whereKey((int) $booking->departure_id)->first();

        // Never serialize Eloquent models, guest secrets, payment evidence, or arbitrary price JSON.
        $lines = [];
        foreach (($booking->price_lines_snapshot ?? []) as $item) {
            $lines[] = [
                'traveler_type' => (string) ($item['traveler_type'] ?? ''),
                'quantity' => (int) ($item['quantity'] ?? 0),
                'regular_unit_minor' => (int) ($item['regular_unit_minor'] ?? 0),
                'unit_minor' => (int) ($item['effective_unit_minor'] ?? 0),
                'line_total_minor' => (int) ($item['line_total_minor'] ?? 0),
            ];
        }

        $response = Inertia::render('admin/bookings/show', [
            'booking' => array_merge($this->summary($booking), [
                'variant_code' => (string) $booking->variant_code,
                'departure_code_snapshot' => (string) $booking->departure_code_snapshot,
                'departure_timezone' => (string) $booking->departure_timezone,
                'regular_total_minor' => (int) $booking->regular_total_minor,
                'discount_minor' => (int) $booking->discount_minor,
                'quoted_at_utc' => $this->utcIsoRaw($booking, 'quoted_at_utc'),
                'confirmed_at' => $this->utcIsoRaw($booking, 'confirmed_at'),
                'cancelled_at' => $this->utcIsoRaw($booking, 'cancelled_at'),
                'confirmation_due_at_utc' => $booking->confirmation_due_at_utc === null
                    ? null : (string) $booking->confirmation_due_at_utc,
                'timeout_review_required_at_utc' => $booking->timeout_review_required_at_utc === null
                    ? null : (string) $booking->timeout_review_required_at_utc,
                'price_lines' => $lines,
            ]),
            'request_contact' => $contact === null ? null : [
                'reference' => (string) $contact['reference'],
                'name' => (string) $contact['name'],
                'email' => (string) $contact['email'],
                'phone' => $contact['phone'] === null ? null : (string) $contact['phone'],
            ],
            'inventory' => $departure === null ? null : [
                'departure_name' => (string) $departure->name,
                'capacity_total' => (int) $departure->capacity_total,
                'seats_committed' => (int) $departure->seats_committed,
                'remaining' => max(0, (int) $departure->capacity_total - (int) $departure->seats_committed),
            ],
            'read_only' => true,
        ])->toResponse($request);

        return $this->privateResponse($response);
    }

    private function authorizeStaff(): void
    {
        Gate::authorize('tours.edit');
        Gate::authorize('tours.publish');
    }

    /** @return array<string, int|string|null> */
    private function summary(TourBooking $booking): array
    {
        return [
            'reference' => (string) $booking->reference_key,
            'status' => (string) $booking->status,
            'payment_state' => (string) $booking->payment_state,
            'tour_title' => (string) $booking->tour_title_snapshot,
            'departure_at_utc' => $this->utcIsoRaw($booking, 'departure_starts_at_utc'),
            'adults' => (int) $booking->adults,
            'children' => (int) $booking->children,
            'seats' => (int) $booking->seats,
            'currency' => (string) $booking->currency,
            'fraction_digits' => (int) $booking->fraction_digits,
            'total_minor' => (int) $booking->total_minor,
            'created_at' => $booking->created_at?->toIso8601String(),
        ];
    }

    /**
     * Booking snapshot timestamps are UTC SQL DATETIME values. Read raw storage
     * values so PHPStan and the serializer agree on the nullable string contract.
     *
     * @return string|null
     */
    private function utcIsoRaw(TourBooking $booking, string $column): ?string
    {
        $raw = $booking->getRawOriginal($column);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        return CarbonImmutable::parse($raw, 'UTC')->toIso8601String();
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
