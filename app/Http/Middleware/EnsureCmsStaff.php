<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Cms\Access\CmsAccess;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCmsStaff
{
    public function __construct(private readonly CmsAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $this->access->canEnterAdmin($user), 403);

        return $next($request);
    }
}
