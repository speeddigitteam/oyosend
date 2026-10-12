<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ __('Unsubscribe') }}</title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f9fafb;color:#1f2937}
main{max-width:420px;margin:16px;padding:32px;background:#fff;border:1px solid #e5e7eb;border-radius:12px}
h1{font-size:20px;margin:0 0 12px}
p{line-height:1.6;margin:0 0 16px}
button{font:inherit;padding:10px 18px;border-radius:8px;border:0;background:#1f2937;color:#fff;cursor:pointer}
</style>
</head>
<body>
<main>
@if ($state === 'confirm')
    <h1>{{ __('Unsubscribe') }}</h1>
    <p>{{ __('Stop receiving marketing messages from :workspace?', ['workspace' => $workspace]) }}</p>
    <form method="post" action="{{ url()->current() }}">
        <button type="submit">{{ __('Unsubscribe') }}</button>
    </form>
@elseif ($state === 'done')
    <h1>{{ __('You are unsubscribed') }}</h1>
    <p>{{ __('You will no longer receive marketing messages from :workspace.', ['workspace' => $workspace]) }}</p>
@else
    <h1>{{ __('This link is not valid') }}</h1>
    <p>{{ __('The unsubscribe link has expired or is not correct.') }}</p>
@endif
</main>
</body>
</html>
