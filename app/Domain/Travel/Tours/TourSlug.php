<?php

declare(strict_types=1);

namespace App\Domain\Travel\Tours;

use App\Domain\Travel\Taxonomy\TaxonomySlug;
use App\Models\Travel\Tour;
use Illuminate\Validation\ValidationException;

/**
 * A tour's public slug remains reserved after soft deletion.
 * Redirect-aware slug editing is intentionally deferred to the SEO module.
 */
final class TourSlug
{
    public function __construct(private readonly TaxonomySlug $taxonomySlugs) {}

    public function normalize(string $value): string
    {
        return $this->taxonomySlugs->normalize($value);
    }

    public function assertAvailable(string $value): string
    {
        $slug = $this->normalize($value);
        if (Tour::withTrashed()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages([
                'slug' => 'This tour slug is already reserved, including archived or deleted tours.',
            ]);
        }

        return $slug;
    }

    public function assertUnchanged(Tour $tour, string $proposed): void
    {
        if ($this->normalize($proposed) !== $tour->slug) {
            throw ValidationException::withMessages([
                'slug' => 'Tour slugs cannot change until public URL redirects are implemented.',
            ]);
        }
    }
}
