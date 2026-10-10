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

        // Wrong codes an account may enter before codes are refused for
        // `lockout_seconds` (recovery codes keep working). Counted per account
        // across every sign-in and workspace: a pending sign-in is discarded
        // after five wrong codes, but the password step can simply be repeated.
        'max_failed_codes' => 10,
        'lockout_seconds' => 900,
    ],

    'invitations' => [
        'expires_after_days' => 7,
    ],

    /*
    | Seconds a workspace-switch handoff token stays valid. Tokens are single use.
    */
    'workspace_handoff_ttl' => 60,

    /*
    | Currencies a workspace can choose for itself (ISO 4217).
    */
    'currencies' => ['BDT', 'USD', 'EUR', 'GBP', 'INR', 'AED', 'SAR', 'SGD', 'MYR', 'AUD', 'CAD', 'JPY'],

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    |
    | `mode` decides what happens when someone signs up on the central site:
    |   open      the workspace is usable as soon as the email is confirmed
    |   approval  it waits (status `pending`) until a Super Admin approves it
    |   closed    there is no sign-up; workspaces are created from the panel
    | The Super Admin panel can change the mode; this is only the default.
    |
    */

    'registration' => [
        'mode' => env('REGISTRATION_MODE', 'open'),
        // Hours the emailed confirmation link stays valid. The workspace
        // address stays reserved for the same time.
        'verification_ttl_hours' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Platform billing
    |--------------------------------------------------------------------------
    |
    | What the platform charges its tenants. `grace_days`, `invoice_lead_days`
    | and `offline_instructions` are defaults the Super Admin panel can change.
    |
    */

    'billing' => [
        // Days a workspace keeps working after a payment was due.
        'grace_days' => 7,
        // Days before a period (or a trial) ends that the next invoice is issued.
        'invoice_lead_days' => 7,
        'invoice_prefix' => env('BILLING_INVOICE_PREFIX', 'INV'),
        // Shown on an unpaid invoice: how to pay outside a gateway (bank
        // details, a phone number). Empty = nothing is shown.
        'offline_instructions' => null,
        // Who issues the invoices; printed on them.
        'issuer' => [
            'name' => env('BILLING_ISSUER_NAME', env('APP_NAME', 'Oyosend')),
            'address' => env('BILLING_ISSUER_ADDRESS'),
            'email' => env('BILLING_ISSUER_EMAIL'),
            'tax_id' => env('BILLING_ISSUER_TAX_ID'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment gateways
    |--------------------------------------------------------------------------
    |
    | A gateway without credentials is not offered: the billing page then says
    | that no online payment method is configured. Nothing here is simulated.
    |
    */

    'gateways' => [
        'sslcommerz' => [
            'store_id' => env('SSLCOMMERZ_STORE_ID'),
            'store_password' => env('SSLCOMMERZ_STORE_PASSWORD'),
            'sandbox' => (bool) env('SSLCOMMERZ_SANDBOX', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Support access (impersonation)
    |--------------------------------------------------------------------------
    |
    | A Super Admin may open a workspace as its owner, with a recorded reason.
    | The session ends by itself after `ttl_minutes`.
    |
    */

    'impersonation' => [
        'ttl_minutes' => 30,
        // Seconds the one-time token that carries the session to the workspace is valid.
        'token_ttl' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Content-Security-Policy
    |--------------------------------------------------------------------------
    |
    |   report-only  the policy is sent but only reported in the browser console
    |   enforce      the browser blocks what the policy does not allow
    |   off          no policy header
    | The other security headers (no sniffing, no framing, referrer, HSTS over
    | HTTPS) are always sent. See App\Core\Http\Middleware\SecurityHeaders.
    |
    */

    'security' => [
        'csp' => env('SECURITY_CSP', 'report-only'),
    ],

];
