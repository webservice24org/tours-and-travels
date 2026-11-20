<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public\Travel;

use App\Domain\Travel\Tours\PublishedTourDetail;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PublicTourShowController
{
    public function __invoke(string $slug, PublishedTourDetail $details): Response
    {
        $tour = $details->find($slug);
        if ($tour === null) {
            throw new NotFoundHttpException('Published tour not found.');
        }

        return Inertia::render('public/tours/show', ['tour' => $tour]);
    }
}
