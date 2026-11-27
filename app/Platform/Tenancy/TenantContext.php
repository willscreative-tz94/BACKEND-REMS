<?php

namespace App\Platform\Tenancy;

use App\Models\Membership;
use App\Models\Organization;
use Closure;

/**
 * The current agency for the whole request, job or seeder. Every
 * tenant-owned model reads it through TenantScope, so a query with no
 * tenant set returns nothing (fail closed) unless the caller explicitly
 * steps outside tenancy with withoutTenancy().
 */
final class TenantContext
{
    private ?Organization $organization = null;

    private ?Membership $membership = null;

    private int $bypassDepth = 0;

    public function set(Organization $organization, ?Membership $membership = null): void
    {
        $this->organization = $organization;
        $this->membership = $membership;

        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId($organization->id);
        }
    }

    public function forget(): void
    {
        $this->organization = null;
        $this->membership = null;

        if (function_exists('setPermissionsTeamId')) {
            setPermissionsTeamId(null);
        }
    }

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?int
    {
        return $this->organization?->id;
    }

    public function check(): bool
    {
        return $this->organization !== null;
    }

    public function membership(): ?Membership
    {
        return $this->membership;
    }

    /** owner | admin | broker | agent | customer, or null for platform staff without a membership. */
    public function role(): ?string
    {
        return $this->membership?->role;
    }

    public function isBypassed(): bool
    {
        return $this->bypassDepth > 0;
    }

    /** Run $callback as $organization, then restore the previous context (seeders, jobs). */
    public function run(Organization $organization, Closure $callback, ?Membership $membership = null): mixed
    {
        [$prevOrg, $prevMembership] = [$this->organization, $this->membership];
        $this->set($organization, $membership);

        try {
            return $callback($organization);
        } finally {
            $prevOrg ? $this->set($prevOrg, $prevMembership) : $this->forget();
        }
    }

    /** Platform console only: lift tenant scopes for cross-tenant counts. Never serve content with it. */
    public function withoutTenancy(Closure $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }
}
