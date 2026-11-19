<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Staff;

use App\Domain\Cms\Staff\CmsStaffUserManager;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Explicit owner-only password setup link for a pending CMS staff account.
 * Reuses Fortify's one-time password reset broker and the normal verification flow.
 * No password, reset token or verification token is returned to the browser.
 */
final class SendCmsStaffSetupLinkController extends Controller
{
    public function __invoke(Request $request, User $user, CmsStaffUserManager $manager): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $manager->assertOwner($actor);

        if ((string) $actor->getKey() === (string) $user->getKey()) {
            throw ValidationException::withMessages(['user' => 'You cannot invite your own account.']);
        }

        $roleKeys = DB::table('cms_role_user')
            ->join('cms_roles', 'cms_roles.id', '=', 'cms_role_user.role_id')
            ->where('cms_role_user.user_id', $user->getKey())
            ->pluck('cms_roles.key')
            ->all();

        if ($roleKeys === [] || in_array('super_admin', $roleKeys, true)) {
            throw ValidationException::withMessages(['user' => 'Only pending, non-super staff accounts can receive setup links.']);
        }
        if ($user->getAttribute('cms_staff_disabled_at') !== null) {
            throw ValidationException::withMessages(['user' => 'Restore CMS staff access before sending a setup link.']);
        }
        if ($user->getAttribute('email_verified_at') !== null) {
            throw ValidationException::withMessages(['user' => 'This email is already verified. Use the standard forgot-password flow if needed.']);
        }
        if (! Route::has('password.reset') || ! Route::has('verification.verify')) {
            throw ValidationException::withMessages([
                'user' => 'Fortify password reset and email verification must both be enabled before inviting staff.',
            ]);
        }

        try {
            // The broker enforces token expiry and reset-link throttling.
            // The reset notification is sent to the target's existing email only.
            $status = Password::broker()->sendResetLink(['email' => $user->email]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to send the setup email. Check your mail transport and application logs.',
            ], 503);
        }

        if ($status === Password::RESET_THROTTLED) {
            return response()->json(['message' => 'A setup link was requested recently. Please try again later.'], 429);
        }
        if ($status !== Password::RESET_LINK_SENT) {
            return response()->json(['message' => 'The setup link could not be sent. Confirm the password broker configuration.'], 422);
        }

        return response()->json([
            'message' => 'Password setup link sent. The recipient must set a password and verify their email before CMS access.',
        ]);
    }
}
