<x-mail::message>
# {{ __('Join :workspace', ['workspace' => $workspace]) }}

@if ($inviter)
{{ __(':inviter has invited you to join the :workspace workspace as :role.', ['inviter' => $inviter, 'workspace' => $workspace, 'role' => $role]) }}
@else
{{ __('You have been invited to join the :workspace workspace as :role.', ['workspace' => $workspace, 'role' => $role]) }}
@endif

<x-mail::button :url="$url">
{{ __('Accept invitation') }}
</x-mail::button>

{{ __('This invitation expires on :date. If you were not expecting it, you can ignore this email.', ['date' => $expiresAt->toFormattedDayDateString()]) }}

{{ config('app.name') }}
</x-mail::message>
