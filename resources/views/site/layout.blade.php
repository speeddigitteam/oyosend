{{--
    The public website's frame (docs/WEBSITE.md). Server-rendered and complete without JavaScript: the one small
    script (resources/js/site.ts) adds the theme switch, the small-screen menu and a gentle reveal on scroll.
--}}
<!DOCTYPE html>
<html lang="{{ $meta->locale }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#ece7df">

    @include('site.partials.head')

    @php($favicon = app(\App\Core\Website\SiteBrand::class)->favicon())
    <link rel="icon" type="{{ $favicon['type'] }}" href="{{ $favicon['href'] }}">
    @if ($favicon['type'] === 'image/svg+xml')
        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    @elseif ($favicon['type'] === 'image/png')
        <link rel="apple-touch-icon" href="{{ $favicon['href'] }}">
    @else
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    @endif

    {{-- The theme is settled before first paint, from the same stored choice the app uses. --}}
    <script @if ($nonce = Vite::cspNonce()) nonce="{{ $nonce }}" @endif>
        (function () {
            try {
                var stored = localStorage.getItem('oyo:theme');
                var dark = stored === 'dark' || ((stored === null || stored === 'system') && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
            } catch (e) {}
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/site.ts'])
</head>
<body class="overflow-x-clip">
    {{-- Colour fields and grain only: the discs belong to the sections, placed behind glass. --}}
    <div class="backdrop" aria-hidden="true"></div>

    <a href="#main" class="glass-solid sr-only z-[70] rounded-control px-4 py-2 text-sm font-medium focus:not-sr-only focus:fixed focus:top-4 focus:left-4">
        {{ __('site.skip') }}
    </a>

    @include('site.partials.header')

    {{-- Discs may reach past a section's edge; the window's edge clips them, so the page never scrolls sideways. --}}
    <main id="main" tabindex="-1" class="overflow-x-clip focus:outline-none">
        @yield('content')
    </main>

    @include('site.partials.footer')
</body>
</html>
