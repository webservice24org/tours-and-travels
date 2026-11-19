<?php

declare(strict_types=1);

namespace App\Http\Controllers\PublicSite;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * P1 foundation only. Categories are navigation previews; packages, pricing,
 * departures, availability and bookings will be database-backed in later phases.
 */
final class HomeController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('public/home', [
            'brand' => [
                'name' => "Bably's Travel & Tour",
                'shortName' => "Bably's Club",
                'tagline' => 'Discover more. Travel confidently.',
            ],
            'categories' => [
                ['key' => 'domestic', 'title' => 'Domestic Tours', 'description' => 'Explore destinations across Bangladesh.'],
                ['key' => 'international', 'title' => 'International Tours', 'description' => 'Discover curated journeys beyond Bangladesh.'],
                ['key' => 'corporate', 'title' => 'Corporate & Business', 'description' => 'Plan group travel and company experiences.'],
                ['key' => 'family', 'title' => 'Family & Education', 'description' => 'Enjoy family outings and educational trips.'],
            ],
        ]);
    }
}
