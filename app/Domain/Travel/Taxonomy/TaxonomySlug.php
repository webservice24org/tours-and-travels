<?php

declare(strict_types=1);

namespace App\Domain\Travel\Taxonomy;

use Illuminate\Validation\ValidationException;

final class TaxonomySlug
{
    /**
     * Canonical URL slugs: lowercase ASCII, 1-160 chars, hyphen-separated.
     * Keep the same slug when a name changes; slug redirects come in a later phase.
     */
    public function normalize(string $input): string
    {
        $slug = strtolower(trim($input));

        if (strlen($slug) > 160 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
            throw ValidationException::withMessages([
                'slug' => 'Use 1-160 lowercase Latin letters, numbers, and single hyphens.',
            ]);
        }

        /** @var mixed $reserved */
        $reserved = config('bablys_cms.reserved_public_segments', []);

        if (is_array($reserved) && in_array($slug, $reserved, true)) {
            throw ValidationException::withMessages([
                'slug' => 'This slug is reserved for a public site route.',
            ]);
        }

        return $slug;
    }
}
