<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Bookings\TourBookingRequestManager;
use App\Mail\GuestBookingRequestLinksMail;
use App\Models\Travel\TourBookingRequest;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Bablys\TourBookingQaTestCase;

final class BookingRequestHttpInterfaceTest extends TourBookingQaTestCase
{
    /** @return array<string,int|string|bool> */
    private function bookingRequestPayload(int $tourId, int $departureId, ?string $nonce = null): array
    {
        return [
            'tour_id' => $tourId,
            'departure_id' => $departureId,
            'variant_code' => 'standard-bdt',
            'adults' => 2,
            'children' => 1,
            'contact_name' => 'Sample Traveler',
            'contact_email' => 'traveler@example.com',
            'contact_phone' => '+8801712345678',
            'consent' => true,
            'submission_id' => $nonce ?? '307455fd-0b73-4c60-8b0b-63e8531ab5d7',
        ];
    }

    public function test_published_request_page_and_validated_server_price_preview(): void
    {
        [$tour, $departure] = $this->readyTour('http-booking-page');
        $this->get('/tours/' . $tour->slug . '/booking-request')->assertOk()
            ->assertInertia(fn($page) => $page->component('public/booking-requests/create')
                ->where('tour.id', $tour->id)
                ->where('nonbinding', true));
        $this->postJson('/booking-requests/quote', [
            'tour_id' => $tour->id,
            'departure_id' => $departure->id,
            'variant_code' => 'standard-bdt',
            'adults' => 2,
            'children' => 1,
        ])->assertOk()->assertJsonPath('quote.total_minor', 3800000)
            ->assertJsonPath('quote.currency', 'BDT')->assertJsonPath('inventory_reserved', false);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
    }

    public function test_unpublished_tour_cannot_offer_request_page(): void
    {
        [$tour] = $this->readyTour('http-hidden-page');
        $tour->status = 'draft';
        $tour->save();
        $this->get('/tours/' . $tour->slug . '/booking-request')->assertNotFound();
    }

    public function test_guest_post_requires_explicit_consent_and_valid_data(): void
    {
        [$tour, $departure] = $this->readyTour('http-guest-invalid');
        $input = $this->bookingRequestPayload($tour->id, $departure->id);
        $input['consent'] = false;
        $this->post('/booking-requests', $input)->assertSessionHasErrors('consent');
        $this->assertDatabaseCount('travel_tour_booking_requests', 0);
    }

    public function test_guest_submits_nonbinding_request_once_and_mail_never_leaks_to_browser(): void
    {
        Mail::fake();
        [$tour, $departure] = $this->readyTour('http-guest-create');
        $input = $this->bookingRequestPayload($tour->id, $departure->id);
        $this->post('/booking-requests', $input)->assertRedirect('/booking-requests/submitted');
        $this->post('/booking-requests', $input)->assertRedirect('/booking-requests/submitted');
        $this->assertDatabaseCount('travel_tour_booking_requests', 1);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
        Mail::assertSent(GuestBookingRequestLinksMail::class, 1);
        $stored = TourBookingRequest::query()->firstOrFail();
        self::assertSame('awaiting_verification', $stored->status);
        self::assertSame('booking-requests-v1', $stored->consent_policy_version);
        self::assertNotNull($stored->consent_accepted_at_utc);
        self::assertNotNull($stored->submission_key_hash);
        $this->get('/booking-requests/submitted')->assertOk()
            ->assertDontSee('token=')->assertDontSee('access=');
    }

    public function test_guest_email_links_verify_only_after_post_and_access_is_owner_scoped(): void
    {
        Mail::fake();
        [$tour, $departure] = $this->readyTour('http-guest-proof');
        $this->post(
            '/booking-requests',
            $this->bookingRequestPayload($tour->id, $departure->id)
        )->assertRedirect('/booking-requests/submitted');
        $email = null;
        Mail::assertSent(GuestBookingRequestLinksMail::class, function (GuestBookingRequestLinksMail $mail) use (&$email): bool {
            $email = $mail;
            return true;
        });
        self::assertInstanceOf(GuestBookingRequestLinksMail::class, $email);
        $verifyFragment = (string) parse_url($email->verifyUrl, PHP_URL_FRAGMENT);
        $manageFragment = (string) parse_url($email->manageUrl, PHP_URL_FRAGMENT);
        parse_str($verifyFragment, $verify);
        parse_str($manageFragment, $manage);
        $row = TourBookingRequest::query()->firstOrFail();
        self::assertSame($row->reference_key, $verify['reference']);
        self::assertSame($row->reference_key, $manage['reference']);
        self::assertSame('awaiting_verification', $row->status);
        $this->postJson('/booking-requests/guest/status', [
            'reference' => $row->reference_key,
            'access_token' => $manage['access'],
        ])->assertNotFound();
        $this->get('/booking-requests/verify')->assertOk();
        self::assertSame('awaiting_verification', $row->fresh()->status); // GET alone cannot verify.
        $this->post('/booking-requests/verify', [
            'reference' => $row->reference_key,
            'token' => $verify['token'],
        ])->assertRedirect('/booking-requests/submitted');
        self::assertSame('verified', $row->fresh()->status);
        $this->postJson('/booking-requests/guest/status', [
            'reference' => $row->reference_key,
            'access_token' => $manage['access'],
        ])->assertOk()->assertJsonPath('data.status', 'verified')
            ->assertDontSee('traveler@example.com')
            ->assertDontSee('contact_email_encrypted')
            ->assertDontSee((string) $manage['access']);
        $this->postJson('/booking-requests/guest/status', [
            'reference' => $row->reference_key,
            'access_token' => str_repeat('0', 64),
        ])->assertNotFound();
        $this->postJson('/booking-requests/guest/withdraw', [
            'reference' => $row->reference_key,
            'access_token' => $manage['access'],
        ])->assertOk();
        self::assertSame('withdrawn', $row->fresh()->status);
    }

    public function test_guest_bad_verification_is_rejected_without_status_change(): void
    {
        Mail::fake();
        [$tour, $departure] = $this->readyTour('http-guest-bad-proof');
        $this->post('/booking-requests', $this->bookingRequestPayload($tour->id, $departure->id));
        $reference = (string) TourBookingRequest::query()->firstOrFail()->reference_key;
        $this->post('/booking-requests/verify', [
            'reference' => $reference,
            'token' => str_repeat('0', 64),
        ])->assertSessionHasErrors('verification');
        self::assertSame('awaiting_verification', TourBookingRequest::query()->firstOrFail()->status);
    }

    public function test_verified_customer_owns_request_and_other_customer_cannot_withdraw(): void
    {
        [$tour, $departure] = $this->readyTour('http-customer');
        $owner = User::factory()->create(['email_verified_at' => now()]);
        $other = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($owner)->post('/booking-requests', $this->bookingRequestPayload($tour->id, $departure->id))
            ->assertRedirect('/booking-requests/submitted');
        $row = TourBookingRequest::query()->firstOrFail();
        self::assertSame($owner->id, $row->customer_id);
        self::assertSame('verified', $row->status);
        $this->get('/account/booking-requests')->assertOk()
            ->assertInertia(fn($page) => $page->component('account/booking-requests/index')
                ->has('requests', 1));
        $this->actingAs($other)->post('/account/booking-requests/' . $row->reference_key . '/withdraw')
            ->assertNotFound();
        self::assertSame('verified', $row->fresh()->status);
        $this->get('/account/booking-requests')->assertInertia(fn($page) => $page->has('requests', 0));
        $this->actingAs($owner)->post('/account/booking-requests/' . $row->reference_key . '/withdraw')
            ->assertRedirect('/account/booking-requests');
        self::assertSame('withdrawn', $row->fresh()->status);
    }

    public function test_email_unverified_account_cannot_create_verified_customer_request(): void
    {
        [$tour, $departure] = $this->readyTour('http-unverified-account');
        $user = User::factory()->create(['email_verified_at' => null]);
        $this->actingAs($user)->post('/booking-requests', $this->bookingRequestPayload($tour->id, $departure->id))
            ->assertForbidden();
        $this->assertDatabaseCount('travel_tour_booking_requests', 0);
    }

    public function test_staff_review_is_restricted_and_has_no_mutation_api(): void
    {
        [$tour, $departure] = $this->readyTour('http-staff-list');
        app(TourBookingRequestManager::class)->createGuest(
            $tour,
            $departure,
            'standard-bdt',
            1,
            0,
            'Private Traveler',
            'private@example.com'
        );
        $this->get('/admin/booking-requests')->assertRedirect();
        $this->actingAs($this->staff('administrator'))
            ->get('/admin/booking-requests')->assertOk()
            ->assertInertia(fn($page) => $page->component('admin/booking-requests/index')
                ->where('review_only', true)->has('requests', 1));
        $this->post('/admin/booking-requests')->assertStatus(405);
    }
}
