<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Staff;

use App\Domain\Cms\Staff\CmsStaffUserManager;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Owner-only Inertia screen. Data mutations still go through the P2B.3A JSON API. */
final class CmsStaffUsersPageController extends Controller
{
    public function __invoke(Request $request, CmsStaffUserManager $manager): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $manager->assertOwner($actor);

        // Read the current RBAC catalog, including custom roles, on each request.
        $roles = app(\App\Domain\Cms\Staff\CmsAssignableRoles::class)->options();

        return Inertia::render('admin/staff-users/index', [
            'viewerId' => (int) $actor->getKey(),
            'roleOptions' => $roles,
        ]);
    }
}
