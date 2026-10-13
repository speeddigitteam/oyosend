<x-mail::message>
# {{ __('Confirm your subscription') }}

{{ __('Someone, hopefully you, asked to receive emails from :workspace on the list ":list". Confirm to be added. Nothing happens until you do.', ['workspace' => $workspace, 'list' => $list]) }}

<x-mail::button :url="$url">
{{ __('Confirm subscription') }}
</x-mail::button>

{{ __('The link works once and expires after :days days. If this was not you, ignore this email and you will not be added.', ['days' => $days]) }}

{{ $workspace }}
</x-mail::message>
