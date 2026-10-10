<x-mail::message>
# {{ __('Payment received') }}

{{ __('We received :amount from :workspace for invoice :number on :date. Thank you.', ['amount' => $amount, 'workspace' => $workspace, 'number' => $number, 'date' => $paidAt->isoFormat('LL')]) }}

{{ __('Payment reference: :reference', ['reference' => $reference]) }}

<x-mail::button :url="$url">
{{ __('View invoice') }}
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
