<x-mail::message>
# {{ __('How did we do?') }}

@if ($name)
{{ __('Hello :name,', ['name' => $name]) }}
@else
{{ __('Hello,') }}
@endif

{{ __('Your request :number (:subject) at :workspace has been resolved. Please tell us how it went. It takes a few seconds.', ['number' => $number, 'subject' => $subject, 'workspace' => $workspace]) }}

<x-mail::button :url="$url">
{{ __('Rate your experience') }}
</x-mail::button>

{{ __('The link works once and expires after a few days.') }}

{{ $workspace }}
</x-mail::message>
