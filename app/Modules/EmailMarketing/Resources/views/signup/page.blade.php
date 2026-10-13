<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ $workspace }}</title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f9fafb;color:#1f2937}
main{width:100%;max-width:460px;margin:16px;padding:28px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-sizing:border-box}
h1{font-size:20px;margin:0 0 12px}
p{line-height:1.6;margin:0 0 16px}
label{display:block;font-weight:600;margin-bottom:6px}
input[type=text],input[type=email]{width:100%;font:inherit;padding:10px;border:1px solid #d1d5db;border-radius:8px;box-sizing:border-box}
.field{margin-bottom:14px}
.hp{position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden}
button{font:inherit;padding:10px 18px;border-radius:8px;border:0;background:#1f2937;color:#fff;cursor:pointer}
button:focus-visible,input:focus-visible{outline:2px solid #1f2937;outline-offset:2px}
.error{color:#991b1b;margin:0 0 12px}
.muted{color:#4b5563;font-size:14px}
</style>
</head>
<body>
<main>
@if ($state === 'form')
    <h1>{{ __('Subscribe to :list', ['list' => $list]) }}</h1>
    <p class="muted">{{ $workspace }}</p>
    @foreach ($errors as $message)
        <p class="error" role="alert">{{ $message }}</p>
    @endforeach
    <form method="post" action="{{ url()->current() }}">
        <div class="field">
            <label for="email">{{ __('Email address') }}</label>
            <input id="email" type="email" name="email" value="{{ $old['email'] ?? '' }}" maxlength="190" required autocomplete="email">
        </div>
        <div class="field">
            <label for="first_name">{{ __('First name (optional)') }}</label>
            <input id="first_name" type="text" name="first_name" value="{{ $old['first_name'] ?? '' }}" maxlength="100" autocomplete="given-name">
        </div>
        <div class="field">
            <label for="last_name">{{ __('Last name (optional)') }}</label>
            <input id="last_name" type="text" name="last_name" value="{{ $old['last_name'] ?? '' }}" maxlength="100" autocomplete="family-name">
        </div>
        <div class="hp" aria-hidden="true">
            <label for="website_url">{{ __('Leave this field empty') }}</label>
            <input id="website_url" type="text" name="website_url" value="" tabindex="-1" autocomplete="off">
        </div>
        <input type="hidden" name="t" value="{{ $trap }}">
        <p class="muted">{{ __('We will send you an email with a link to confirm. You are only added after you confirm.') }}</p>
        <button type="submit">{{ __('Subscribe') }}</button>
    </form>
@elseif ($state === 'sent')
    <h1>{{ __('Check your inbox') }}</h1>
    <p>{{ __('If the address can be added, we have sent a confirmation link to it. You are only subscribed once you open it and confirm. It is valid for :days days.', ['days' => $days]) }}</p>
@elseif ($state === 'confirm')
    <h1>{{ __('Confirm your subscription') }}</h1>
    <p>{{ __('Confirm that you want to receive emails from :workspace on the list ":list".', ['workspace' => $workspace, 'list' => $list]) }}</p>
    <form method="post" action="{{ url()->current() }}">
        <button type="submit">{{ __('Confirm subscription') }}</button>
    </form>
@elseif ($state === 'subscribed')
    <h1>{{ __('You are subscribed') }}</h1>
    <p>{{ __('Thank you. :workspace has recorded your confirmation. Every email has an unsubscribe link.', ['workspace' => $workspace]) }}</p>
@elseif ($state === 'blocked')
    <h1>{{ __('We could not add this address') }}</h1>
    <p>{{ __('This address cannot receive marketing email from :workspace right now, so nothing was changed.', ['workspace' => $workspace]) }}</p>
@elseif ($state === 'unavailable')
    <h1>{{ __('Please try again later') }}</h1>
    <p>{{ __('We could not complete your subscription right now. Your link still works; open it again later.') }}</p>
@elseif ($state === 'busy')
    <h1>{{ __('Too many requests') }}</h1>
    <p>{{ __('Please wait a while before trying again.') }}</p>
@else
    <h1>{{ __('This link is not valid') }}</h1>
    <p>{{ __('The link has expired, was already used or is not correct.') }}</p>
@endif
</main>
</body>
</html>
