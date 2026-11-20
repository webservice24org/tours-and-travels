<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Travel;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** P3B.3: Inertia shell. All writes remain in the P3B.1 authorized JSON API. */
final class TourCategoriesPageController extends Controller
{
    public function __invoke(): Response
    {
        Gate::authorize('taxonomy.manage');

        return Inertia::render('admin/tour-categories/index');
    }
}
