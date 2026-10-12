<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ __('How did we do?') }}</title>
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f9fafb;color:#1f2937}
main{width:100%;max-width:460px;margin:16px;padding:32px;background:#fff;border:1px solid #e5e7eb;border-radius:12px;box-sizing:border-box}
h1{font-size:20px;margin:0 0 12px}
p{line-height:1.6;margin:0 0 16px}
fieldset{border:0;margin:0 0 16px;padding:0}
legend{font-weight:600;margin-bottom:8px}
label.option{display:flex;align-items:center;gap:10px;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;margin-bottom:8px;cursor:pointer}
label.option:focus-within{outline:2px solid #1f2937;outline-offset:2px}
textarea{width:100%;min-height:96px;font:inherit;padding:10px;border:1px solid #d1d5db;border-radius:8px;box-sizing:border-box}
button{font:inherit;padding:10px 18px;border-radius:8px;border:0;background:#1f2937;color:#fff;cursor:pointer}
.error{color:#991b1b;margin:0 0 12px}
.muted{color:#4b5563;font-size:14px}
</style>
</head>
<body>
<main>
@if ($state === 'form')
    <h1>{{ __('How did we do?') }}</h1>
    <p class="muted">{{ $workspace }} &middot; {{ $number }} &middot; {{ $subject }}</p>
    @foreach ($errors as $message)
        <p class="error" role="alert">{{ $message }}</p>
    @endforeach
    <form method="post" action="{{ url()->current() }}">
        <fieldset>
            <legend>{{ __('Your rating') }}</legend>
            @foreach ([5 => __('Very satisfied'), 4 => __('Satisfied'), 3 => __('Neutral'), 2 => __('Dissatisfied'), 1 => __('Very dissatisfied')] as $value => $label)
                <label class="option">
                    <input type="radio" name="rating" value="{{ $value }}" required @checked((int) ($rating ?? 0) === $value)>
                    <span>{{ $value }} &ndash; {{ $label }}</span>
                </label>
            @endforeach
        </fieldset>
        <p>
            <label for="comment">{{ __('Anything you would like to add? (optional)') }}</label>
            <textarea id="comment" name="comment" maxlength="2000">{{ $comment ?? '' }}</textarea>
        </p>
        <button type="submit">{{ __('Send feedback') }}</button>
    </form>
@elseif ($state === 'done')
    <h1>{{ __('Thank you for your feedback') }}</h1>
    <p>{{ __('Your answer was sent to :workspace.', ['workspace' => $workspace]) }}</p>
@else
    <h1>{{ __('This link is not valid') }}</h1>
    <p>{{ __('The survey link has expired, was already used or is not correct.') }}</p>
@endif
</main>
</body>
</html>
