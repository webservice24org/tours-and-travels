<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Roles;

use App\Domain\Cms\Roles\CmsCustomRoleManager;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Authenticated owner-only Inertia shell; writes use the existing P2B.4A JSON API. */
final class CmsRolesPageController extends Controller
{
    public function __invoke(Request $request, CmsCustomRoleManager $manager): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $manager->assertOwner($actor);

        return Inertia::render('admin/roles/index');
    }
}
