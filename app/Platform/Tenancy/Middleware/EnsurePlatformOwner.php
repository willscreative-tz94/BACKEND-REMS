<?php

namespace App\Platform\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Platform Console: only the REMS platform owner. */
class EnsurePlatformOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isPlatformOwner(), 403, 'Platform owners only.');

        return $next($request);
    }
}
