<?php

// Multitenancy: every agency (Organization) is an isolated tenant. Requests
// name their tenant with the X-Organization header (or a subdomain under
// base_domain); see app/Platform/Tenancy.
//
// mode:
//   multitenant  REMS marketplace — many agencies, each in its own workspace,
//                public directory, agency switcher.
//   enterprise   One agency runs REMS as its own white-labelled system. Every
//                request is pinned to `enterprise_organization` (header and
//                subdomain are ignored), the directory lists only that agency.
return [
    'mode' => env('TENANCY_MODE', 'multitenant'),
    'enterprise_organization' => env('TENANCY_ENTERPRISE_ORGANIZATION', ''),
    'base_domain' => env('TENANCY_BASE_DOMAIN', ''),
    'header' => 'X-Organization',
    'reserved_slugs' => ['www', 'api', 'app', 'admin', 'console', 'platform', 'mail', 'static', 'assets', 'support', 'help'],
];
