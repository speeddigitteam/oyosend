<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Domains
    |--------------------------------------------------------------------------
    |
    | The central (marketing) site is served on `domain`. Every tenant lives on
    | `{tenant}.domain` and the Super Admin panel on `{admin_subdomain}.domain`.
    | Custom tenant domains are a future feature: the `tenant_domains` table
    | already stores a `type`, only the routing for it is not built yet.
    |
    */

    'domain' => env('APP_DOMAIN', 'localhost'),

    'admin_subdomain' => env('ADMIN_SUBDOMAIN', 'admin'),

    /*
    | Labels a tenant may never claim as its subdomain.
    */
    'reserved_subdomains' => [
        'admin', 'www', 'api', 'app', 'apps', 'auth', 'login', 'mail', 'smtp',
        'ftp', 'cdn', 'static', 'assets', 'status', 'docs', 'help', 'support',
        'billing', 'dashboard', 'platform', 'root', 'system', 'internal',
        'staging', 'dev', 'test', 'demo', 'ws', 'reverb', 'horizon',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tenant defaults
    |--------------------------------------------------------------------------
    |
    | Applied to a new tenant; each tenant can change all three later.
    |
    */

    'defaults' => [
        'locale' => 'en',
        'timezone' => 'Asia/Dhaka',
        'currency' => 'BDT',
    ],

    /*
    | Locales the interface is translated into (code => native name).
    */
    'locales' => [
        'en' => 'English',
        'bn' => 'বাংলা',
    ],

    /*
    |--------------------------------------------------------------------------
    | Two-factor authentication
    |--------------------------------------------------------------------------
    |
    | Super Admin accounts must enrol in TOTP before they can use the panel.
    | The environment switch is ignored in production, where it is always on.
    |
    */

    'two_factor' => [
        'required_for_platform' => env('APP_ENV') === 'production'
            ? true
            : (bool) env('PLATFORM_REQUIRE_2FA', true),
        'recovery_codes' => 8,
        'challenge_ttl' => 600, // seconds a password-verified login may wait for its code
    ],

    'invitations' => [
        'expires_after_days' => 7,
    ],

    /*
    | Seconds a workspace-switch handoff token stays valid. Tokens are single use.
    */
    'workspace_handoff_ttl' => 60,

];
