<x-mail::message>
# {{ __('Confirm your email') }}

{{ __('Hello :name, confirm this address to create the :company workspace at :address.', ['name' => $name, 'company' => $company, 'address' => $address]) }}

<x-mail::button :url="$url">
{{ __('Confirm and create workspace') }}
</x-mail::button>

{{ trans_choice('{1} This link works once and expires in :count hour.|[2,*] This link works once and expires in :count hours.', $expiresInHours, ['count' => $expiresInHours]) }} {{ __('If you did not sign up, you can ignore this email: nothing has been created.') }}

{{ config('app.name') }}
</x-mail::message>
