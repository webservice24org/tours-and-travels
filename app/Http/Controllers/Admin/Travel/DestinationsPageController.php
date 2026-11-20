<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Travel;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** P3B.4 — protected UI shell. Mutations continue through the existing P3B.2 API. */
final class DestinationsPageController extends Controller
{
    public function __invoke(): Response
    {
        Gate::authorize('taxonomy.manage');

        return Inertia::render('admin/destinations/index');
    }
}
