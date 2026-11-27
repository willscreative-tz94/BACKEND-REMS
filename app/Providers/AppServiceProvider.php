<?php

namespace App\Providers;

use App\Platform\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // scoped: reset between requests (Octane) and queued jobs.
        $this->app->scoped(TenantContext::class);
    }

    public function boot(): void
    {
    }
}
