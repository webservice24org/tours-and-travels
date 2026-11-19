<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Cms\Access\CmsAccess;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class BablysAdminHomeController
{
    public function __invoke(Request $request, CmsAccess $access): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('admin/index', [
            'staff' => ['name' => (string) $user->name],
            'roles' => $access->roleKeysFor($user),
            'permissions' => $access->permissionKeysFor($user),
        ]);
    }
}
