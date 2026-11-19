<?php

declare(strict_types=1);

namespace App\Domain\Cms\Enums;

/** Independently of reports/flags, only approved reviews can appear publicly. */
enum ReviewStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Hidden = 'hidden';

    public function isPublic(): bool
    {
        return $this === self::Approved;
    }
}
