<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Bookings\TourBookingRequestManager;
use App\Models\Travel\TourItineraryDay;
use App\Models\Travel\TourMedia;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Bablys\TourBookingQaTestCase;

/** P3G: cross-boundary privacy, publication and untrusted-input regressions. */
final class PublicTourReleaseSecurityBoundaryTest extends TourBookingQaTestCase
{
    /** Fail before RefreshDatabase can migrate the wrong database. */
    protected function setUp(): void
    {
        $env = (string) (getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? ''));
        $connection = (string) (getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? ''));
        $database = (string) (getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? ''));
        if ($env !== 'testing' || $connection !== 'mysql'
            || preg_match('/\A[a-zA-Z][a-zA-Z0-9_]*_test\z/D', $database) !== 1
            || str_ends_with($database, 'race_test')) {
            throw new \RuntimeException('P3G requires APP_ENV=testing, DB_CONNECTION=mysql, and a dedicated NON-race *_test database.');
        }
        parent::setUp();
    }

    public function test_public_cards_and_detail_only_render_allowlisted_public_media(): void
    {
        Storage::fake('public');
        [$tour] = $this->readyTour('p3g-media-boundary');
        Storage::disk('public')->put('tours/p3g-safe.webp', 'fixture');
        TourMedia::query()->create([
            'tour_id' => (int) $tour->getKey(), 'disk' => 'local',
            'path' => 'staff/secrets.webp', 'mime_type' => 'image/webp',
            'file_size' => 100, 'width' => 300, 'height' => 200,
            'alt_text' => 'Private data', 'is_featured' => true,
        ]);
        TourMedia::query()->create([
            'tour_id' => (int) $tour->getKey(), 'disk' => 'public',
            'path' => 'tours/p3g-safe.webp', 'mime_type' => 'image/webp',
            'file_size' => 100, 'width' => 300, 'height' => 200,
            'alt_text' => 'Safe coast', 'sort_order' => 1,
        ]);
        $this->get('/tours')->assertOk()->assertInertia(fn ($page) => $page
            ->where('tours.0.cover_image.alt', 'Safe coast')
            ->missing('tours.0.cover_image.path')
            ->missing('tours.0.cover_image.disk'));
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.cover_image.alt', 'Safe coast')
            ->where('tour.gallery.0.alt', 'Safe coast')
            ->missing('tour.gallery.0.path'))
            ->assertDontSee('staff/secrets.webp');
    }

    public function test_itinerary_markup_is_plain_text_and_staff_inventory_is_excluded(): void
    {
        [$tour, $departure] = $this->readyTour('p3g-itinerary-privacy');
        TourItineraryDay::query()->create([
            'tour_id' => $tour->getKey(), 'day_number' => 1,
            'title' => '<b>Day one</b>', 'location_label' => 'Harbour',
            'description' => '<script>unsafe()</script><b>Safe description</b>',
            'overnight_location' => null, 'meals' => ['<b>Lunch</b>'],
            'highlights' => ['<i>Viewpoint</i>'],
        ]);
        $departure->update(['internal_notes' => 'PRIVATE-OPS-KEEP-OUT', 'meeting_point' => 'PRIVATE-MEET-POINT']);
        $this->get('/tours/'.$tour->slug)->assertOk()->assertInertia(fn ($page) => $page
            ->where('tour.itinerary.0.title', 'Day one')
            ->where('tour.itinerary.0.description', 'unsafe()Safe description')
            ->where('tour.itinerary.0.meals.0', 'Lunch')
            ->where('tour.itinerary.0.highlights.0', 'Viewpoint')
            ->missing('tour.upcoming_departures.0.internal_notes')
            ->missing('tour.upcoming_departures.0.meeting_point')
            ->missing('tour.upcoming_departures.0.seats_committed')
            ->missing('tour.upcoming_departures.0.booking_closes_at'));
    }

    public function test_unpublished_tours_never_have_public_booking_or_detail_routes(): void
    {
        [$draft] = $this->readyTour('p3g-draft');
        [$scheduled] = $this->readyTour('p3g-scheduled');
        [$archived] = $this->readyTour('p3g-archived');
        $draft->update(['status' => 'draft', 'published_at' => null]);
        $scheduled->update(['status' => 'scheduled', 'published_at' => null, 'scheduled_for' => now('UTC')->addDays(2)]);
        $archived->update(['status' => 'archived', 'published_at' => null]);
        foreach ([$draft, $scheduled, $archived] as $tour) {
            $this->get('/tours/'.$tour->slug)->assertNotFound();
            $this->get('/tours/'.$tour->slug.'/booking-request')->assertNotFound();
        }
        $this->get('/tours')->assertOk()->assertInertia(fn ($page) => $page->has('tours', 0));
    }

    public function test_untrusted_cross_tour_departure_cannot_preselect_or_quote(): void
    {
        [$tour] = $this->readyTour('p3g-own-tour');
        [$other, $foreign] = $this->readyTour('p3g-foreign-tour');
        // Both tour fixtures have the same default departure time to the second.
        // Shift this one's UTC timestamp so its date cannot match the first tour.
        $foreign->update(['starts_at' => now('UTC')->addDays(175)->format('Y-m-d H:i:s')]);
        $hint = str_replace(' ', 'T', (string) $foreign->fresh()?->starts_at).'Z';
        $this->get('/tours/'.$tour->slug.'/booking-request?departure_at='.rawurlencode($hint))
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('tour.slug', (string) $tour->slug)
                ->where('departure_selection.selected_id', null)
                ->where('departure_selection.requested', true));
        $this->postJson('/booking-requests/quote', [
            'tour_id' => (int) $tour->getKey(),
            'departure_id' => (int) $foreign->getKey(),
            'variant_code' => 'standard-bdt', 'adults' => 1, 'children' => 0,
        ])->assertNotFound();
        self::assertNotSame((int) $tour->getKey(), (int) $other->getKey());
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }

    public function test_filled_departure_is_not_preselected_or_quoted_as_available(): void
    {
        [$tour, $departure] = $this->readyTour('p3g-filled');
        $hint = str_replace(' ', 'T', (string) $departure->starts_at).'Z';
        DB::table('travel_tour_departures')->where('id', $departure->getKey())
            ->update(['seats_committed' => (int) $departure->capacity_total]);
        $this->get('/tours/'.$tour->slug.'/booking-request?departure_at='.rawurlencode($hint))
            ->assertOk()->assertInertia(fn ($page) => $page
                ->where('departure_selection.selected_id', null)
                ->has('departures', 0));
        $this->postJson('/booking-requests/quote', [
            'tour_id' => (int) $tour->getKey(), 'departure_id' => (int) $departure->getKey(),
            'variant_code' => 'standard-bdt', 'adults' => 1, 'children' => 0,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('travel_tour_bookings', 0);
    }

    public function test_guest_status_needs_correct_secret_and_verified_email(): void
    {
        [$tour, $departure] = $this->readyTour('p3g-guest-boundary');
        $receipt = app(TourBookingRequestManager::class)->createGuest(
            $tour, $departure, 'standard-bdt', 1, 0,
            'Guest Security', 'proof-p3g@example.test'
        );
        $payload = [
            'reference' => (string) $receipt->request->reference_key,
            'access_token' => (string) $receipt->accessToken,
        ];
        $this->postJson('/booking-requests/guest/status', $payload)->assertNotFound();
        app(TourBookingRequestManager::class)->verifyGuest(
            $receipt->request, (string) $receipt->verificationToken
        );
        $this->postJson('/booking-requests/guest/status', array_merge($payload, [
            'access_token' => str_repeat('f', 64),
        ]))->assertNotFound();
        $this->postJson('/booking-requests/guest/status', $payload)->assertOk()
            ->assertJsonPath('data.status', 'verified')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertDontSee((string) $receipt->accessToken);
        $this->assertDatabaseCount('travel_tour_bookings', 0);
    }

    public function test_other_customer_cannot_read_or_accept_private_quote(): void
    {
        [$tour, $departure] = $this->readyTour('p3g-quote-ownership');
        $owner = User::factory()->create(['email_verified_at' => now('UTC')]);
        $other = User::factory()->create(['email_verified_at' => now('UTC')]);
        $row = app(TourBookingRequestManager::class)->createCustomer(
            $owner, $tour, $departure, 'standard-bdt', 1, 0
        )->request;
        $url = '/account/booking-requests/'.$row->reference_key;
        $this->actingAs($other)->postJson($url.'/quote-review')->assertNotFound();
        $this->postJson($url.'/quote-accept', [
            'revision' => 1, 'fingerprint' => str_repeat('a', 64),
        ])->assertNotFound();
        $this->actingAs($owner)->postJson($url.'/quote-review')->assertOk();
        $this->assertDatabaseCount('travel_tour_bookings', 0);
        self::assertSame(0, (int) $departure->fresh()->seats_committed);
    }

    public function test_invalid_query_structures_are_rejected_without_publication_leak(): void
    {
        $this->get('/tours?sort%5B%5D=title_asc')->assertNotFound();
        $this->get('/tours?category=%3Cscript%3E')->assertNotFound();
        $this->get('/tours?page=0')->assertNotFound();
        $this->get('/tours?q%5B%5D=untrusted')->assertNotFound();
        $this->assertDatabaseCount('travel_tour_bookings', 0);
    }
}
