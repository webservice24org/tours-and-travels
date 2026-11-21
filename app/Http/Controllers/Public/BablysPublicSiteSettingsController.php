<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Cms\Settings\BablysSiteSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** Safe public projection: published settings only, never editor drafts. */
final class BablysPublicSiteSettingsController extends Controller
{
    public function __invoke(BablysSiteSettings $settings): JsonResponse
    {
        return response()->json(['settings' => $settings->publicSnapshot()])
            ->header('Cache-Control', 'public, max-age=60');
    }
}
