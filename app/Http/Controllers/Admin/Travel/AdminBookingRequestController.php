<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Travel;

use App\Domain\Travel\Bookings\TourBookingRequestPresentation;
use App\Http\Controllers\Controller;
use App\Models\Travel\TourBookingRequest;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/** Read-only limited staff review; no public reservation or unconsented conversion. */
final class AdminBookingRequestController extends Controller
{
    public function __invoke(TourBookingRequestPresentation $presenter): Response
    {
        Gate::authorize('tours.edit');
        Gate::authorize('tours.publish');

        $rows = TourBookingRequest::query()->with(['tour', 'departure'])
            ->orderByDesc('id')->limit(100)->get();

        $response = Inertia::render('admin/booking-requests/index', [
            'requests' => $rows->map(fn (TourBookingRequest $row): array => $presenter->staff($row))->all(),
            'review_only' => true,
        ])->toResponse(request());
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        return $response;
    }
}
