<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Multi-tenant mode (shared Postgres server, one DB per company)
    |--------------------------------------------------------------------------
    */
    'enabled' => (bool) env('TENANCY_ENABLED', false),

    /*
    | Header used by the SPA / API clients to select a tenant.
    | Subdomain resolution is also supported (acme.example.com → acme).
    */
    'header' => env('TENANCY_HEADER', 'X-Tenant'),

    /*
    | Fallback when no header/subdomain is present (local / single-tenant deploys).
    */
    'default' => env('TENANCY_DEFAULT', 'chaacreek'),

    /*
    | Central registry database on the same Postgres server.
    */
    'platform_database' => env('TENANCY_PLATFORM_DATABASE', 'payroll_platform'),

    /*
    | Prefix used when creating new tenant databases: payroll_{slug}
    */
    'database_prefix' => env('TENANCY_DATABASE_PREFIX', 'payroll_'),

    /*
    | Hostnames that should not be treated as tenant subdomains.
    */
    'central_domains' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TENANCY_CENTRAL_DOMAINS', 'localhost,127.0.0.1,www'))
    ))),

    /*
    | Host suffixes that always use TENANCY_DEFAULT (Azure shared hostnames, etc.).
    */
    'central_domain_suffixes' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'TENANCY_CENTRAL_DOMAIN_SUFFIXES',
            'azurecontainerapps.io,web.core.windows.net,localhost'
        ))
    ))),
];
