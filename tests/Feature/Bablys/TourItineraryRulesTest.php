<?php

declare(strict_types=1);

namespace Tests\Feature\Bablys;

use App\Domain\Travel\Itineraries\TourItineraryRules;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class TourItineraryRulesTest extends TestCase
{
    public function test_empty_and_partial_draft_itinerary_are_allowed(): void
    {
        $rules = app(TourItineraryRules::class);
        self::assertSame([], $rules->normalize([], 3));
        $days = $rules->normalize([['title' => ' Arrival ']], 3);
        self::assertSame(1, $days[0]['day_number']);
        self::assertSame('Arrival', $days[0]['title']);
        self::assertNull($days[0]['description']);
        self::assertSame([], $days[0]['meals']);
    }

    public function test_meals_are_canonicalized_and_highlights_preserve_order(): void
    {
        $days = app(TourItineraryRules::class)->normalize([[ 
            'title' => 'Safari', 'location_label' => '  Bandarban  ',
            'meals' => ['dinner', 'breakfast'],
            'highlights' => ['Hill walk', 'Local food'],
        ]], 1);
        self::assertSame(['breakfast', 'dinner'], $days[0]['meals']);
        self::assertSame(['Hill walk', 'Local food'], $days[0]['highlights']);
        self::assertSame('Bandarban', $days[0]['location_label']);
    }

    public function test_rejects_more_itinerary_days_than_duration(): void
    {
        $this->expectException(ValidationException::class);
        app(TourItineraryRules::class)->normalize([['title' => 'One'], ['title' => 'Two']], 1);
    }

    public function test_rejects_out_of_order_explicit_day_numbers(): void
    {
        $this->expectException(ValidationException::class);
        app(TourItineraryRules::class)->normalize([['day_number' => 2, 'title' => 'Wrong']], 2);
    }

    public function test_rejects_html_in_day_descriptions(): void
    {
        $this->expectException(ValidationException::class);
        app(TourItineraryRules::class)->normalize([['title' => 'Day', 'description' => '<script>alert(1)</script>']], 1);
    }

    public function test_rejects_duplicate_meals(): void
    {
        $this->expectException(ValidationException::class);
        app(TourItineraryRules::class)->normalize([['title' => 'Day', 'meals' => ['lunch', 'lunch']]], 1);
    }

    public function test_rejects_duplicate_highlights_ignoring_case(): void
    {
        $this->expectException(ValidationException::class);
        app(TourItineraryRules::class)->normalize([['title' => 'Day', 'highlights' => ['River', 'river']]], 1);
    }

    public function test_rejects_unsupported_mass_assignment_fields(): void
    {
        $this->expectException(ValidationException::class);
        app(TourItineraryRules::class)->normalize([['title' => 'Day', 'tour_id' => 999]], 1);
    }

    public function test_rejects_blank_required_title(): void
    {
        $this->expectException(ValidationException::class);
        app(TourItineraryRules::class)->normalize([['title' => ' ']], 1);
    }
}
