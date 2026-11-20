<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Travel;

use App\Http\Controllers\Controller;
use App\Models\Travel\Tour;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class TourEditorPageController extends Controller
{
    public function create(): Response
    {
        Gate::authorize('tours.create');
        return $this->page('create');
    }

    public function edit(Tour $tour): Response
    {
        Gate::authorize('tours.edit');
        if (in_array($tour->status->value, ['published', 'scheduled'], true)) {
            Gate::authorize('tours.publish');
        }
        return $this->page('edit', (int) $tour->getKey());
    }

    private function page(string $mode, ?int $tourId = null): Response
    {
        return Inertia::render('admin/tours/editor', [
            'mode' => $mode,
            'tourId' => $tourId,
            'can' => [
                'create' => Gate::allows('tours.create'),
                'edit' => Gate::allows('tours.edit'),
                'publish' => Gate::allows('tours.publish'),
            ],
        ]);
    }
}
