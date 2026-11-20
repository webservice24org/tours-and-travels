<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Navigation;

use App\Domain\Cms\Access\CmsAccess;
use App\Domain\Cms\Navigation\CmsAdminNavigation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only navigation model. Actual destination routes enforce permissions again. */
final class CmsAdminNavigationController
{
    public function __invoke(
        Request $request,
        CmsAccess $access,
        CmsAdminNavigation $navigation,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return response()->json([
            'data' => $navigation->groupsFor($user, $access),
        ])->header('Cache-Control', 'private, no-store, max-age=0');
    }
}
