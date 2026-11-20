<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Bookings\TourBookingRequestManager;
use App\Models\Travel\TourBookingRequest;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Bablys\TourBookingQaTestCase;

final class TourBookingRequestSchemaTest extends TourBookingQaTestCase
{
    public function test_request_table_has_protected_contact_and_identity_columns(): void
    {
        self::assertTrue(Schema::hasTable('travel_tour_booking_requests'));
        foreach ([
            'reference_key', 'tour_id', 'departure_id', 'customer_id', 'booking_id',
            'status', 'variant_code', 'adults', 'children',
            'contact_name_encrypted', 'contact_email_encrypted', 'contact_phone_encrypted',
            'contact_email_lookup_hmac', 'verification_token_hash', 'access_token_hash',
            'verification_expires_at_utc', 'expires_at_utc', 'verified_at_utc',
            'withdrawn_at_utc', 'converted_at_utc',
        ] as $field) {
            self::assertTrue(Schema::hasColumn('travel_tour_booking_requests', $field), $field);
        }
    }

    public function test_a_request_belongs_to_its_tour_and_departure(): void
    {
        [$tour, $departure] = $this->readyTour('intent-relations');
        $receipt = app(TourBookingRequestManager::class)->createGuest(
            $tour, $departure, 'standard-bdt', 1, 0, 'Guest Example', 'guest@example.com'
        );
        $stored = TourBookingRequest::query()->firstOrFail();
        self::assertSame($tour->id, $stored->tour?->id);
        self::assertSame($departure->id, $stored->departure?->id);
        self::assertSame($receipt->request->id, $stored->id);
        self::assertNull($stored->booking);
    }

    public function test_contact_fields_and_tokens_are_hidden_from_model_serialization(): void
    {
        [$tour, $departure] = $this->readyTour('intent-json-hidden');
        $receipt = app(TourBookingRequestManager::class)->createGuest(
            $tour, $departure, 'standard-bdt', 1, 0, 'Hidden Example', 'hidden@example.com'
        );
        $serialized = (string) json_encode($receipt->request->toArray());
        foreach (['contact_name_encrypted', 'contact_email_encrypted',
            'contact_email_lookup_hmac', 'verification_token_hash', 'access_token_hash',
        ] as $key) {
            self::assertStringNotContainsString($key, $serialized);
        }
        self::assertStringNotContainsString('hidden@example.com', $serialized);
        self::assertStringNotContainsString((string) $receipt->verificationToken, $serialized);
    }

    public function test_reference_is_unique_and_request_has_no_allocation_foreign_key(): void
    {
        [$tour, $departure] = $this->readyTour('intent-refs');
        $service = app(TourBookingRequestManager::class);
        $first = $service->createGuest($tour, $departure, 'standard-bdt', 1, 0, 'First Guest', 'first@example.com');
        $second = $service->createGuest($tour, $departure, 'standard-bdt', 1, 0, 'Other Guest', 'other@example.com');
        self::assertNotSame($first->request->reference_key, $second->request->reference_key);
        self::assertFalse(Schema::hasColumn('travel_tour_booking_requests', 'seat_allocation_id'));
        $this->assertDatabaseCount('travel_tour_seat_allocations', 0);
    }
}
