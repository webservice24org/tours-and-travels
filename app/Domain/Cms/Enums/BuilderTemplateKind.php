<?php

declare(strict_types=1);

namespace App\Domain\Cms\Enums;

/** Template kinds share the same versioned-builder infrastructure. */
enum BuilderTemplateKind: string
{
    case Header = 'header';
    case Footer = 'footer';
    case Page = 'page';
    case TourArchive = 'tour_archive';
    case TourDetail = 'tour_detail';
    case DestinationArchive = 'destination_archive';
    case DestinationDetail = 'destination_detail';
    case BlogArchive = 'blog_archive';
    case BlogDetail = 'blog_detail';
    case GalleryArchive = 'gallery_archive';
    case GalleryDetail = 'gallery_detail';
}
