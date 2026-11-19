<?php

declare(strict_types=1);

namespace App\Domain\Cms\Enums;

/**
 * Proposed editorial lifecycle for CMS content and published builder snapshots.
 * Review and publish permissions are enforced by future policies, not this enum.
 */
enum PublicationStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case Scheduled = 'scheduled';
    case Published = 'published';
    case Archived = 'archived';

    public function isPublic(): bool
    {
        // Scheduled content is not public until the scheduler publishes it.
        return $this === self::Published;
    }
}
