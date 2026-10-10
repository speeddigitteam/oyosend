<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#ece7df">

    <title inertia>{{ config('app.name') }}</title>

    <link rel="icon" type="image/svg+xml" href="/favicon.svg">

    {{-- Resolve the theme before first paint so there is no flash of the wrong one. --}}
    <script @if ($nonce = Vite::cspNonce()) nonce="{{ $nonce }}" @endif>
        (function () {
            try {
                var stored = localStorage.getItem('oyo:theme');
                var dark = stored === 'dark' || ((stored === null || stored === 'system') && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.dataset.theme = dark ? 'dark' : 'light';
            } catch (e) {}
        })();
    </script>

    {{-- Only the named routes of the current area are sent to the browser. --}}
    @routes($area ?? 'central', Vite::cspNonce())
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body>
    @inertia
</body>
</html>
