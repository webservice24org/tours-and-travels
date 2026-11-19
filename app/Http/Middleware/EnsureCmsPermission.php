<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Cms\Access\RbacCatalog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/** Example: ->middleware(EnsureCmsPermission::class.':tours.publish') */
final class EnsureCmsPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        abort_unless(array_key_exists($permission, RbacCatalog::permissions()), 403);

        // A centrally registered Gate also works inside controllers and jobs.
        Gate::authorize($permission);

        return $next($request);
    }
}
