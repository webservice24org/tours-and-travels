<?php

declare(strict_types=1);

namespace App\Domain\Travel\Tours;

use App\Domain\Cms\Enums\PublicationStatus;
use App\Models\Travel\Tour;

/**
 * P3F.3C public tour detail. No staff-only inventory, prices,
 * booking references, capacity or private media exposed.
 */
final class PublishedTourDetail
{
    /**
     * @return array{title:string,slug:string,summary:?string,duration_days:int,is_featured:bool,cover_image:?array{src:string,alt:string,width:int,height:int},gallery:list<array{src:string,alt:string,width:int,height:int}>,itinerary:list<array{day_number:int,title:string,location:?string,description:?string,overnight:?string,meals:list<string>,highlights:list<string>}>,upcoming_departures:list<array{name:string,starts_at_utc:string,ends_at_utc:?string,timezone:string}>,destinations:list<array{name:string,slug:string}>,categories:list<array{name:string,slug:string}>,canonical_url:string,enquiry_url:string}|null
     */
    public function find(string $slug): ?array
    {
        // Same publication scope as the SQL-paginated listing, including its
        // linked-taxonomy restrictions and scheduled-publication rules.
        $tour = Tour::query()->publiclyAvailable()
            ->where('slug', $slug)
            ->first(['id', 'title', 'slug', 'summary', 'duration_days', 'is_featured']);

        if ($tour === null) {
            return null;
        }

        $destinations = [];
        foreach ($tour->destinations()
            ->where('travel_destinations.status', PublicationStatus::Published->value)
            ->orderBy('travel_destinations.name')
            ->get(['travel_destinations.id', 'travel_destinations.name', 'travel_destinations.slug']) as $destination) {
            $destinations[] = ['name' => (string) $destination->name, 'slug' => (string) $destination->slug];
        }

        $categories = [];
        foreach ($tour->categories()
            ->where('travel_tour_categories.status', PublicationStatus::Published->value)
            ->orderBy('travel_tour_categories.name')
            ->get(['travel_tour_categories.id', 'travel_tour_categories.name', 'travel_tour_categories.slug']) as $category) {
            $categories[] = ['name' => (string) $category->name, 'slug' => (string) $category->slug];
        }

        $covers = app(PublicTourCardMedia::class)->coversFor([(int) $tour->id => (string) $tour->title]);

        return [
            'title' => (string) $tour->title,
            'slug' => (string) $tour->slug,
            'summary' => $tour->summary,
            'duration_days' => (int) $tour->duration_days,
            'is_featured' => (bool) $tour->is_featured,
            'cover_image' => $covers[(int) $tour->id] ?? null,
            'gallery' => app(PublicTourGallery::class)->forPublishedTour((int) $tour->id, (string) $tour->title),
            'itinerary' => app(PublishedTourSchedule::class)->itineraryFor((int) $tour->id),
            'upcoming_departures' => app(PublishedTourSchedule::class)->upcomingFor((int) $tour->id),
            'destinations' => $destinations,
            'categories' => $categories,
            // Absolute public canonical URL, independent of tracking query strings.
            'canonical_url' => route('public.tours.show', ['slug' => (string) $tour->slug]),
            'enquiry_url' => route('booking-requests.create', ['tour' => (string) $tour->slug]),
        ];
    }
}
