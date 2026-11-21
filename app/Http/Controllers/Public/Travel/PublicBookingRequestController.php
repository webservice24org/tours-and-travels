<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Travel;

use App\Domain\Cms\Enums\PublicationStatus;
use App\Domain\Travel\Bookings\TourBookingReadiness;
use App\Domain\Travel\Bookings\TourBookingRequestManager;
use App\Domain\Travel\Bookings\TourBookingRequestPresentation;
use App\Domain\Travel\Tours\PublicTourSeatAvailability;
use App\Http\Controllers\Controller;
use App\Mail\GuestBookingRequestLinksMail;
use App\Models\Travel\Tour;
use App\Models\Travel\TourBookingRequest;
use App\Models\Travel\TourDeparture;
use App\Models\Travel\TourPriceVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * P3E.5B.2B: nonbinding booking requests only. CSRF is provided by the web
 * middleware. Guest secrets are sent solely to validated email, NEVER in HTML,
 * JSON, redirects, query strings, logs or Inertia props.
 */
final class PublicBookingRequestController extends Controller
{
    public function __construct(
        private readonly TourBookingRequestManager $requests,
        private readonly TourBookingReadiness $readiness,
        private readonly TourBookingRequestPresentation $presenter,
    ) {}

    public function create(Request $request, Tour $tour): Response
    {
        $this->assertPublicTour($tour);
        $user = $request->user();
        $customer = $user instanceof User ? $user : null;
        $nowUtc = now('UTC')->format('Y-m-d H:i:s');
        $departures = TourDeparture::query()->where('tour_id', $tour->getKey())
            ->where('status', 'open')->where('starts_at', '>', $nowUtc)
            ->where(static function (Builder $query) use ($nowUtc): void {
                $query->whereNull('booking_closes_at')
                    ->orWhere('booking_closes_at', '>', $nowUtc);
            })
            ->whereColumn('capacity_total', '>', 'seats_committed')
            ->orderBy('starts_at')->orderBy('id')->limit(100)
            ->get(['id', 'name', 'starts_at', 'timezone_name', 'capacity_total', 'seats_committed']);
        $seatSummaries = app(PublicTourSeatAvailability::class)->forDepartures($departures);

        // Tour detail links carry only a public UTC date hint, never inventory identifiers.
        // Compare it solely against this tour's currently requestable departure options.
        $requested = $request->query->has('departure_at');
        $rawDepartureAt = $request->query->all()['departure_at'] ?? null;
        $selectedDepartureId = null;
        if ($requested && is_string($rawDepartureAt)
            && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $rawDepartureAt) === 1) {
            $sqlUtc = str_replace('T', ' ', substr($rawDepartureAt, 0, -1));
            $match = $departures->first(static fn (TourDeparture $row): bool => (string) $row->starts_at === $sqlUtc);
            if ($match instanceof TourDeparture) {
                $selectedDepartureId = (int) $match->getKey();
            }
        }
        $departureSelection = [
            'requested' => $requested,
            'selected_id' => $selectedDepartureId,
            'notice' => $requested && $selectedDepartureId === null
                ? 'That departure is no longer requestable. Please select an available departure.'
                : null,
        ];
        $variants = TourPriceVariant::query()->where('tour_id', $tour->getKey())
            ->where('is_active', true)->orderBy('sort_order')->limit(50)
            ->get(['code', 'name', 'currency']);

        return Inertia::render('public/booking-requests/create', [
            'tour' => ['id' => (int) $tour->getKey(), 'title' => (string) $tour->title, 'slug' => (string) $tour->slug],
            'departures' => $departures->map(static fn (TourDeparture $row): array => [
                'id' => (int) $row->getKey(), 'name' => (string) $row->name,
                'starts_at' => (string) $row->starts_at, 'timezone' => (string) $row->timezone_name,
                'seat_summary' => $seatSummaries[(int) $row->getKey()],
            ])->all(),
            'variants' => $variants->map(static fn (TourPriceVariant $row): array => [
                'code' => (string) $row->code, 'name' => (string) $row->name,
                'currency' => (string) $row->currency,
            ])->all(),
            'customer' => $customer === null ? null : [
                'name' => (string) $customer->name, 'email' => (string) $customer->email,
                'email_verified' => $customer->email_verified_at !== null,
            ],
            'nonbinding' => true,
            'departure_selection' => $departureSelection,
            'submission_id' => (string) Str::uuid(),
        ]);
    }

    public function quote(Request $request): JsonResponse
    {
        $input = $request->validate($this->selectionRules());
        [$tour, $departure] = $this->selection($input);
        $check = $this->readiness->inspect($tour, $departure, (string) $input['variant_code'],
            (int) $input['adults'], (int) $input['children'], now('UTC'));
        if (! $check['ready_for_internal_allocation'] || $check['quote'] === null) {
            throw ValidationException::withMessages(['departure_id' => 'The selected departure or pricing option is not currently available.']);
        }
        $price = $check['quote'];

        return $this->privateJson([
            'nonbinding' => true,
            'inventory_reserved' => false,
            'valid_at_utc' => now('UTC')->toIso8601String(),
            'quote' => [
                'currency' => $price['currency'], 'fraction_digits' => $price['fraction_digits'],
                'total_minor' => $price['total_minor'], 'regular_total_minor' => $price['regular_total_minor'],
                'discount_minor' => $price['discount_minor'],
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $input = $request->validate(array_merge($this->selectionRules(), [
            'contact_name' => ['nullable', 'string', 'min:2', 'max:120'],
            'contact_email' => ['nullable', 'email:rfc', 'max:254'],
            'contact_phone' => ['nullable', 'string', 'max:25'],
            'consent' => ['required', 'accepted'],
            'submission_id' => ['required', 'uuid'],
        ]));
        $actor = $request->user();
        $customer = $actor instanceof User ? $actor : null;
        if ($customer !== null) {
            abort_unless($customer->email_verified_at !== null, 403);
        } else {
            $guestContact = $request->validate([
                'contact_name' => ['required', 'string', 'min:2', 'max:120'],
                'contact_email' => ['required', 'email:rfc', 'max:254'],
            ]);
            $input['contact_name'] = $guestContact['contact_name'];
            $input['contact_email'] = $guestContact['contact_email'];
        }
        [$tour, $departure] = $this->selection($input);
        $email = strtolower(trim($customer === null ? (string) $input['contact_email'] : (string) $customer->email));
        $this->limitRequest($request, $email, $customer);
        // A session ID can rotate or differ between HTTP retries (including tests).
        // Scope the UUID to the verified customer ID or normalized guest email,
        // so distinct owners cannot accidentally share the same submission key.
        $submissionOwner = $customer === null
            ? 'guest:'.$email
            : 'customer:'.(string) $customer->getKey();
        $idempotency = hash_hmac(
            'sha256',
            'bablys:booking-request:v2|'.$submissionOwner.'|'.(string) $input['submission_id'],
            (string) config('app.key')
        );
        $previous = TourBookingRequest::query()->where('submission_key_hash', $idempotency)->first();
        if ($previous !== null) {
            return $this->submitted((string) $previous->reference_key, $customer === null);
        }
        // Fail closed for mail drivers that write the secret links to local logs in production.
        if ($customer === null
            && ((string) config('mail.default') === 'log'
                || (app()->environment('production') && (string) config('mail.default') === 'array'))) {
            abort(503, 'Guest email verification is temporarily unavailable.');
        }

        try {
            $receipt = DB::transaction(function () use ($customer, $tour, $departure, $input, $idempotency) {
                $result = $customer === null
                    ? $this->requests->createGuest($tour, $departure, (string) $input['variant_code'],
                        (int) $input['adults'], (int) $input['children'],
                        (string) $input['contact_name'], (string) $input['contact_email'],
                        isset($input['contact_phone']) ? (string) $input['contact_phone'] : null)
                    : $this->requests->createCustomer($customer, $tour, $departure,
                        (string) $input['variant_code'], (int) $input['adults'],
                        (int) $input['children'], isset($input['contact_phone']) ? (string) $input['contact_phone'] : null);
                DB::table('travel_tour_booking_requests')->where('id', $result->request->getKey())->update([
                    'submission_key_hash' => $idempotency,
                    'consent_policy_version' => 'booking-requests-v1',
                    'consent_accepted_at_utc' => now('UTC')->format('Y-m-d H:i:s'),
                ]);
                return $result;
            }, 3);
        } catch (QueryException $error) {
            // Convergent retry if a parallel submission inserted the same unique key.
            $existing = TourBookingRequest::query()->where('submission_key_hash', $idempotency)->first();
            if ($existing === null) {
                throw $error;
            }
            return $this->submitted((string) $existing->reference_key, $customer === null);
        }

        if ($customer === null) {
            $verificationToken = $receipt->verificationToken;
            $accessToken = $receipt->accessToken;
            if ($verificationToken === null || $accessToken === null) {
                abort(500, 'Guest email delivery contract invalid.');
            }
            $reference = (string) $receipt->request->reference_key;
            $verify = url('/booking-requests/verify').'#reference='.rawurlencode($reference).'&token='.$verificationToken;
            $manage = url('/booking-requests/manage').'#reference='.rawurlencode($reference).'&access='.$accessToken;
            try {
                Mail::to($email)->send(new GuestBookingRequestLinksMail($reference, $verify, $manage));
            } catch (Throwable) {
                // Do not retain an inaccessible active guest request after delivery failure.
                $this->requests->withdrawGuest($receipt->request, $accessToken);
                DB::table('travel_tour_booking_requests')->where('id', $receipt->request->getKey())
                    ->update(['submission_key_hash' => null]);
                abort(503, 'Email delivery is temporarily unavailable. Please retry.');
            }
        }

        return $this->submitted((string) $receipt->request->reference_key, $customer === null);
    }

    public function submittedPage(Request $request): Response
    {
        return Inertia::render('public/booking-requests/submitted', [
            'reference' => (string) $request->session()->get('booking_request_reference', ''),
            'guest' => (bool) $request->session()->get('booking_request_guest', true),
        ]);
    }

    public function verifyPage(): Response
    {
        return Inertia::render('public/booking-requests/verify');
    }

    public function verifyGuest(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'reference' => ['required', 'regex:/^BRQ-[a-f0-9]{32}$/D'],
            'token' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
        ]);
        $row = TourBookingRequest::query()->where('reference_key', $input['reference'])->firstOrFail();
        $this->requests->verifyGuest($row, (string) $input['token']);

        return to_route('booking-requests.submitted')->with([
            'booking_request_reference' => (string) $row->reference_key,
            'booking_request_guest' => true,
        ]);
    }

    public function managePage(): Response
    {
        return Inertia::render('public/booking-requests/manage');
    }

    public function guestStatus(Request $request): JsonResponse
    {
        $input = $request->validate($this->guestAccessRules());
        return $this->privateJson(['data' => $this->presenter->guestVerified(
            (string) $input['reference'], (string) $input['access_token']
        )]);
    }

    public function guestWithdraw(Request $request): JsonResponse
    {
        $input = $request->validate($this->guestAccessRules());
        $reference = (string) $input['reference'];
        $token = (string) $input['access_token'];
        $this->presenter->guestVerified($reference, $token);
        $row = TourBookingRequest::query()->where('reference_key', $reference)->firstOrFail();
        $this->requests->withdrawGuest($row, $token);

        return $this->privateJson(['message' => 'The booking request has been withdrawn.']);
    }

    public function customerIndex(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $customer = $request->user();
        abort_unless($customer instanceof User && $customer->email_verified_at !== null, 403);

        $response = Inertia::render('account/booking-requests/index', [
            'requests' => $this->presenter->customerRows($customer),
            'nonbinding' => true,
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        return $response;
    }

    public function customerWithdraw(Request $request, string $reference): RedirectResponse
    {
        $customer = $request->user();
        abort_unless($customer instanceof User && $customer->email_verified_at !== null, 403);
        abort_unless(preg_match('/^BRQ-[a-f0-9]{32}$/D', $reference) === 1, 404);
        $row = TourBookingRequest::query()->where('reference_key', $reference)
            ->where('customer_id', $customer->getKey())->firstOrFail();
        $this->requests->withdrawCustomer($customer, $row);

        return to_route('booking-requests.customer.index');
    }

    /** @return array<string,array<int,string>> */
    private function selectionRules(): array
    {
        return [
            'tour_id' => ['required', 'integer', 'min:1'],
            'departure_id' => ['required', 'integer', 'min:1'],
            'variant_code' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{0,59}$/D'],
            'adults' => ['required', 'integer', 'between:1,30'],
            'children' => ['required', 'integer', 'between:0,30'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: Tour, 1: TourDeparture}
     */
    private function selection(array $input): array
    {
        $tour = Tour::query()->whereKey((int) $input['tour_id'])->firstOrFail();
        $this->assertPublicTour($tour);
        $departure = TourDeparture::query()->whereKey((int) $input['departure_id'])
            ->where('tour_id', $tour->getKey())->firstOrFail();
        return [$tour, $departure];
    }

    private function assertPublicTour(Tour $tour): void
    {
        abort_unless(! $tour->trashed() && $tour->status === PublicationStatus::Published
            && $tour->published_at !== null && $tour->published_at->isPast(), 404);
    }

    /** @return array<string,array<int,string>> */
    private function guestAccessRules(): array
    {
        return [
            'reference' => ['required', 'regex:/^BRQ-[a-f0-9]{32}$/D'],
            'access_token' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
        ];
    }

    private function limitRequest(Request $request, string $email, ?User $customer): void
    {
        $keyedEmail = hash_hmac('sha256', $email, (string) config('app.key'));
        $keyedIp = hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));
        $keys = ['bablys:booking-request:email:'.$keyedEmail => 4,
                 'bablys:booking-request:ip:'.$keyedIp => 12];
        if ($customer !== null) {
            $keys['bablys:booking-request:customer:'.(int) $customer->getKey()] = 8;
        }
        foreach ($keys as $key => $limit) {
            abort_if(RateLimiter::tooManyAttempts($key, $limit), 429, 'Too many booking requests.');
        }
        foreach ($keys as $key => $limit) {
            RateLimiter::hit($key, 3600);
        }
    }

    private function submitted(string $reference, bool $guest): RedirectResponse
    {
        return to_route('booking-requests.submitted')->with([
            'booking_request_reference' => $reference, 'booking_request_guest' => $guest,
        ]);
    }

    /** @param array<string,mixed> $payload */
    private function privateJson(array $payload): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
