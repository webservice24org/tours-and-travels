<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Travel;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** P3D.2: Inertia shell only; P3D.1 remains authoritative for JSON writes. */
final class ToursPageController extends Controller
{
    public function __invoke(): Response
    {
        Gate::authorize('tours.view');

        return Inertia::render('admin/tours/index', [
            'can' => [
                'create' => Gate::allows('tours.create'),
                'edit' => Gate::allows('tours.edit'),
                'publish' => Gate::allows('tours.publish'),
            ],
        ]);
    }
}
