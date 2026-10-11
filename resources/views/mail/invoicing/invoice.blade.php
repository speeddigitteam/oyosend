<x-mail::message>
@if ($reminder === 'overdue')
# {{ __('Invoice :number is overdue', ['number' => $number]) }}
@elseif ($reminder)
# {{ __('Payment reminder for invoice :number', ['number' => $number]) }}
@else
# {{ __('Invoice :number', ['number' => $number]) }}
@endif

{{ __('Hello :name,', ['name' => $customer]) }}

@if ($reminder)
{{ __('This is a reminder that invoice :number from :workspace has an outstanding balance of :balance.', ['number' => $number, 'workspace' => $workspace, 'balance' => $balance]) }}
@else
{{ __('Please find invoice :number from :workspace attached. The total is :total.', ['number' => $number, 'workspace' => $workspace, 'total' => $total]) }}
@endif

@if ($dueOn)
{{ __('It is due on :date.', ['date' => $dueOn->isoFormat('LL')]) }}
@endif

@if ($note)
{{ $note }}
@endif

{{ __('The invoice is attached as a document you can open in a browser and print or save as PDF.') }}

{{ $workspace }}
</x-mail::message>
