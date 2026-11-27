<?php

namespace App\Platform\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * role:owner,admin,broker — the member's role in the current agency must be
 * one of those listed. Platform owners pass (they administer every agency).
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if ($request->user()?->isPlatformOwner() && ! tenant()->membership()) {
            return $next($request);
        }

        abort_unless(in_array(tenant()->role(), $roles, true), 403, 'Your role in this agency does not allow this action.');

        return $next($request);
    }
}
