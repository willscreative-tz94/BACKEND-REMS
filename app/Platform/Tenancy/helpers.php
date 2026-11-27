<?php

use App\Platform\Tenancy\TenantContext;

if (! function_exists('tenant')) {
    /** The current TenantContext (the agency this request works in). */
    function tenant(): TenantContext
    {
        return app(TenantContext::class);
    }
}
