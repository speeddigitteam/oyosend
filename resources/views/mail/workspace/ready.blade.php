<x-mail::message>
# {{ __(':workspace is ready', ['workspace' => $workspace]) }}

@if ($setPasswordUrl)
{{ __('A workspace has been created for you at :address. Choose a password to sign in for the first time.', ['address' => $address]) }}

<x-mail::button :url="$setPasswordUrl">
{{ __('Choose a password') }}
</x-mail::button>
@else
{{ __('Your workspace at :address is ready. Sign in with your email address and password.', ['address' => $address]) }}

<x-mail::button :url="$loginUrl">
{{ __('Sign in') }}
</x-mail::button>
@endif

{{ config('app.name') }}
</x-mail::message>
