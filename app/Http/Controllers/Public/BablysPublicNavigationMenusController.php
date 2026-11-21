<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Cms\Navigation\BablysNavigationMenus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

final class BablysPublicNavigationMenusController extends Controller
{
    public function __invoke(BablysNavigationMenus $menus): JsonResponse
    {
        return response()->json(['menus' => (object) $menus->publicSnapshot()])
            ->header('Cache-Control', 'no-store, max-age=0');
    }
}
