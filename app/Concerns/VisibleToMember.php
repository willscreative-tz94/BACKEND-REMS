<?php

namespace App\Concerns;

use App\Models\Agent;
use App\Models\Customer;

use Illuminate\Database\Eloquent\Builder;

/**
 * Row-level visibility inside an agency: customers only see their own
 * records, agents see the ones assigned to them (or their team, for a
 * broker), owners/admins see everything. Models with customer_id/agent_id.
 */
trait VisibleToMember
{
    public function scopeVisible(Builder $query): Builder
    {
        $role = tenant()->role();
        $user = auth()->user();

        if (! $role || in_array($role, ['owner', 'admin'], true)) {
            return $query;
        }

        if ($role === 'customer') {
            $customerId = Customer::idForUser($user?->id);

            return $query->where($this->qualifyColumn('customer_id'), $customerId ?? 0);
        }

        $agent = Agent::forUser($user?->id);
        if (! $agent) {
            return $query->whereRaw('1 = 0');
        }

        $ids = $role === 'broker' ? $agent->teamIds() : [$agent->id];

        return $query->whereIn($this->qualifyColumn('agent_id'), $ids);
    }
}
