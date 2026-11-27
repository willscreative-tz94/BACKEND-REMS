<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Customer;

abstract class Controller
{
    protected function role(): ?string
    {
        return tenant()->role() ?? (auth()->user()?->isPlatformOwner() ? 'admin' : null);
    }

    protected function isAdmin(): bool
    {
        return in_array($this->role(), ['owner', 'admin'], true);
    }

    protected function isStaff(): bool
    {
        return in_array($this->role(), ['owner', 'admin', 'broker', 'agent'], true);
    }

    protected function isCustomer(): bool
    {
        return $this->role() === 'customer';
    }

    protected function requireRole(string ...$roles): void
    {
        abort_unless(in_array($this->role(), $roles, true), 403, 'Your role in this agency does not allow this action.');
    }

    protected function currentAgent(): ?Agent
    {
        return Agent::forUser(auth()->id());
    }

    protected function currentCustomer(): ?Customer
    {
        return Customer::where('user_id', auth()->id())->first();
    }

    /** Agents may only change records assigned to them (brokers: their team's). */
    protected function ensureCanManage(?int $agentId): void
    {
        if ($this->isAdmin()) {
            return;
        }
        $agent = $this->currentAgent();
        $allowed = $agent ? ($this->role() === 'broker' ? $agent->teamIds() : [$agent->id]) : [];
        abort_unless($agentId && in_array($agentId, $allowed, true), 403, 'This record is assigned to another agent.');
    }

    protected function perPage(): int
    {
        return min(100, max(5, (int) request('per_page', 15)));
    }
}
