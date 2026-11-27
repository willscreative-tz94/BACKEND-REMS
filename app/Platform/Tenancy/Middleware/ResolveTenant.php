<?php

namespace App\Platform\Tenancy\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reads X-Organization (or the subdomain; or the pinned agency in enterprise mode), loads the agency, confirms the
 * signed-in user is an active member (platform owners may open any), and
 * sets TenantContext. Runs before route model binding, so another
 * tenant's {property} is a 404.
 *
 *   tenant           tenant required
 *   tenant:optional  resolve if named, carry on without it if not
 *   tenant:public    resolve if named, no membership needed (public listings)
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $identifier = self::identifierFrom($request);

        if ($identifier === null) {
            if (in_array($mode, ['optional', 'public'], true)) {
                return $next($request);
            }

            return response()->json(['code' => 'organization_required', 'message' => 'Choose an agency to continue.'], 400);
        }

        $organization = Organization::findByIdentifier($identifier);
        if (! $organization) {
            return response()->json(['code' => 'organization_not_found', 'message' => 'Agency not found.'], 404);
        }

        if ($mode === 'public') {
            if ($organization->status !== 'active') {
                return response()->json(['code' => 'organization_suspended', 'message' => 'This agency is not available.'], 423);
            }
            tenant()->set($organization);

            return $next($request);
        }

        $user = $request->user();
        $membership = $user ? $organization->memberships()->where('user_id', $user->id)->first() : null;
        $platformAccess = ! $membership && $user?->isPlatformOwner();

        if (! $platformAccess && ! $membership?->is_active) {
            if ($mode === 'optional') {
                return $next($request);
            }

            return response()->json(['code' => 'not_a_member', 'message' => 'You are not a member of this agency.'], 403);
        }

        if ($organization->status === 'suspended' && ! $user?->isPlatformOwner()) {
            return response()->json([
                'code' => 'organization_suspended',
                'message' => 'This agency has been suspended. Contact the platform administrator.',
            ], 423);
        }

        tenant()->set($organization, $membership);
        $user?->unsetRelation('roles')->unsetRelation('permissions');

        return $next($request);
    }

    public static function identifierFrom(Request $request): ?string
    {
        // Enterprise install: one agency, whatever the client asks for.
        if (config('tenancy.mode') === 'enterprise' && config('tenancy.enterprise_organization')) {
            return (string) config('tenancy.enterprise_organization');
        }

        $header = trim((string) $request->header(config('tenancy.header')));
        if ($header !== '') {
            return $header;
        }

        $host = strtolower($request->getHost());
        $base = strtolower((string) config('tenancy.base_domain'));

        if ($base !== '' && str_ends_with($host, '.'.$base)) {
            $sub = substr($host, 0, -strlen('.'.$base));

            return $sub !== '' && ! str_contains($sub, '.') && ! in_array($sub, config('tenancy.reserved_slugs'), true) ? $sub : null;
        }

        return null;
    }
}
