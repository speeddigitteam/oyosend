<x-mail::message>
# {{ __('Invoice :number', ['number' => $number]) }}

@if ($plan && $periodStart && $periodEnd)
{{ __('An invoice of :total has been issued to :workspace for the :plan plan, :from to :to.', ['total' => $total, 'workspace' => $workspace, 'plan' => $plan, 'from' => $periodStart->isoFormat('LL'), 'to' => $periodEnd->isoFormat('LL')]) }}
@else
{{ __('An invoice of :total has been issued to :workspace.', ['total' => $total, 'workspace' => $workspace]) }}
@endif

@if ($dueAt)
{{ __('It is due on :date.', ['date' => $dueAt->isoFormat('LL')]) }}
@endif

<x-mail::button :url="$url">
{{ __('View invoice') }}
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
