<?php

/*
|--------------------------------------------------------------------------
| Public website
|--------------------------------------------------------------------------
|
| The company behind the platform, as the website, its structured data and
| the contact form present it (docs/WEBSITE.md). These are the defaults: an
| operator changes them in the Super Admin panel (Website > Company), which
| stores only what changed (App\Core\Settings\PlatformSettings, `website.*`).
| The words of the pages are in lang/{en,bn}/site*.php, and an operator can
| change those too (Website > Texts).
|
*/

return [

    'company' => [
        'name' => 'Oyosend',
        'email' => 'info@oyosend.com',

        // As written on the website; the international form for links and structured data is derived from it.
        'phone_1' => '+88 0179 22 88 555',
        'phone_2' => '+88 018 292 33 333',

        // In English, also used for the structured data; the country is Bangladesh.
        'street' => '1st Floor, House 08, Road 13/A, Sector 06',
        'locality' => 'Uttara',
        'city' => 'Dhaka',
        'postal_code' => '1230',

        // The same address as written on the Bengali pages, one line each.
        'address_bn' => "১ম তলা, বাড়ি ০৮, রোড ১৩/এ, সেক্টর ০৬\nউত্তরা, ঢাকা-১২৩০\nবাংলাদেশ",

        'map_url' => 'https://www.google.com/maps/search/?api=1&query=House+08%2C+Road+13%2FA%2C+Sector+06%2C+Uttara%2C+Dhaka+1230',
    ],

    // The logo and favicon an operator uploaded (Super Admin > Website > Company), by file name; none means the built-in
    // Oyosend mark. Kept on `disk` under platform/brand/ and served by the central site at /brand/{file}.
    'brand' => [
        'logo' => null,
        'logo_dark' => null,
        'favicon' => null,
        'disk' => env('SITE_BRAND_DISK', 'local'),
    ],

    // The company's own pages elsewhere: shown in the footer and named in the structured data (`sameAs`).
    'social' => [
        'facebook' => null,
        'linkedin' => null,
        'youtube' => null,
        'instagram' => null,
        'x' => null,
    ],

    // Where the contact form's messages are mailed, besides being kept in the Super Admin panel.
    'contact_recipient' => env('SITE_CONTACT_EMAIL', 'info@oyosend.com'),

    // The date the website's words last changed, for the sitemap; a change made in the panel counts from then.
    'content_updated' => '2026-10-14',

    // The blog (Super Admin > Website > Blog): its images are kept on `disk` under platform/blog/ and served by the
    // central site at /blog/media/{file}. Dates are written in `timezone`, the company's own.
    'blog' => [
        'disk' => env('SITE_BLOG_DISK', env('SITE_BRAND_DISK', 'local')),
        'per_page' => 12,
        'timezone' => 'Asia/Dhaka',
    ],

];
