<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Cms\Navigation\BablysNavigationMenus;
use App\Domain\Cms\Settings\BablysSiteSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * One read-only projection for the public React header/footer.
 * Both domains return published snapshots, never editorial drafts or staff data.
 */
final class BablysPublicChromeController extends Controller
{
    public function __invoke(BablysSiteSettings $settings, BablysNavigationMenus $menus): JsonResponse
    {
        return response()->json([
            'settings' => $settings->publicSnapshot(),
            'menus' => (object) $menus->publicSnapshot(),
        ])->header('Cache-Control', 'no-store, max-age=0')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
