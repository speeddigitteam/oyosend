<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', $locale) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $heading }} {{ $number }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f2f0ec; color: #1f2328; font: 14px/1.5 "Segoe UI", system-ui, -apple-system, "Noto Sans", "Noto Sans Bengali", sans-serif; }
        .toolbar { max-width: 210mm; margin: 16px auto 0; padding: 0 16px; display: flex; gap: 8px; justify-content: flex-end; }
        .toolbar button { font: inherit; padding: 8px 14px; border: 1px solid #8a8f98; background: #fff; color: #1f2328; border-radius: 6px; cursor: pointer; }
        .sheet { max-width: 210mm; min-height: 297mm; margin: 16px auto; padding: 18mm 16mm; background: #fff; border: 1px solid #dcd8d0; }
        header.top { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; }
        .logo { max-height: 64px; max-width: 220px; margin-bottom: 8px; display: block; }
        h1 { margin: 0; font-size: 26px; letter-spacing: .02em; }
        .muted { color: #55595f; }
        .badge { display: inline-block; margin-top: 6px; padding: 2px 10px; border: 1px solid #8a8f98; border-radius: 999px; font-size: 12px; }
        .badge.paid { border-color: #1f6b3a; color: #1f6b3a; }
        .badge.failed { border-color: #9c2a1f; color: #9c2a1f; }
        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin: 28px 0 20px; }
        h2 { margin: 0 0 4px; font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: #55595f; }
        .parties p { margin: 0; }
        dl.rows { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: 2px 16px; }
        dl.rows dt { color: #55595f; }
        dl.rows dd { margin: 0; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; margin-top: 18px; }
        th { text-align: start; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #55595f; border-bottom: 2px solid #1f2328; padding: 6px 8px; }
        td { padding: 6px 8px; border-bottom: 1px solid #dcd8d0; vertical-align: top; }
        .num { text-align: end; white-space: nowrap; }
        .columns { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        .totals { margin: 24px 0 0 auto; width: 60%; }
        .totals td { border: 0; padding: 4px 8px; }
        .totals tr.strong td { border-top: 2px solid #1f2328; font-weight: 700; font-size: 15px; }
        .block { margin-top: 24px; }
        .block p { margin: 0; white-space: pre-line; }
        @page { size: A4; margin: 12mm; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { border: 0; margin: 0; padding: 0; max-width: none; min-height: 0; }
            tr, .totals { break-inside: avoid; }
        }
        @media (max-width: 600px) {
            .sheet { padding: 16px; }
            .parties, .columns { grid-template-columns: 1fr; }
            .totals { width: 100%; }
        }
    </style>
</head>
<body>
@if (! ($embedded ?? false))
    <div class="toolbar">
        <button type="button" id="print-document">{{ __('Print / Save as PDF') }}</button>
    </div>
@endif
<main class="sheet">
    <header class="top">
        <div>
            @if ($logo)
                <img class="logo" src="{{ $logo }}" alt="{{ $orgName }}">
            @endif
            <strong>{{ $seller['name'] }}</strong>
            @foreach ($seller['lines'] as $line)
                <div class="muted">{{ $line }}</div>
            @endforeach
        </div>
        <div style="text-align: end">
            <h1>{{ $heading }}</h1>
            @if ($number)
                <div>{{ $number }}</div>
            @endif
            <span class="badge {{ $payment['statusKey'] }}">{{ $payment['status'] }}</span>
        </div>
    </header>

    <section class="parties">
        <div>
            <h2>{{ __('Employee') }}</h2>
            <p><strong>{{ $employee['name'] }}</strong></p>
            <dl class="rows">
                @foreach ($employee['rows'] as $item)
                    <dt>{{ $item['label'] }}</dt><dd>{{ $item['value'] }}</dd>
                @endforeach
            </dl>
        </div>
        <div>
            <h2>{{ __('Pay period') }}</h2>
            <p><strong>{{ $period['name'] }}</strong></p>
            <dl class="rows">
                <dt>{{ __('Period') }}</dt><dd>{{ $period['range'] }}</dd>
                @if ($period['payDate'])
                    <dt>{{ __('Pay date') }}</dt><dd>{{ $period['payDate'] }}</dd>
                @endif
                @foreach ($facts as $item)
                    <dt>{{ $item['label'] }}</dt><dd>{{ $item['value'] }}</dd>
                @endforeach
                @foreach ($payment['details'] as $item)
                    <dt>{{ $item['label'] }}</dt><dd>{{ $item['value'] }}</dd>
                @endforeach
            </dl>
        </div>
    </section>

    <section class="columns">
        <table>
            <thead><tr><th>{{ __('Earnings') }}</th><th class="num">{{ __('Amount') }}</th></tr></thead>
            <tbody>
            @foreach ($earnings as $line)
                <tr><td>{{ $line['name'] }}</td><td class="num">{{ $line['amount'] }}</td></tr>
            @endforeach
            @foreach ($reimbursements as $line)
                <tr><td>{{ $line['name'] }} <span class="muted">({{ __('Reimbursement') }})</span></td><td class="num">{{ $line['amount'] }}</td></tr>
            @endforeach
            </tbody>
        </table>
        <table>
            <thead><tr><th>{{ __('Deductions') }}</th><th class="num">{{ __('Amount') }}</th></tr></thead>
            <tbody>
            @forelse ($deductions as $line)
                <tr><td>{{ $line['name'] }}</td><td class="num">{{ $line['amount'] }}</td></tr>
            @empty
                <tr><td class="muted" colspan="2">-</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>

    <table class="totals">
        @foreach ($totals as $row)
            <tr class="{{ ($row['strong'] ?? false) ? 'strong' : '' }}">
                <td>{{ $row['label'] }}</td>
                <td class="num">{{ $row['value'] }}</td>
            </tr>
        @endforeach
    </table>

    @if (count($employer) > 0)
        <section class="block">
            <h2>{{ __('Employer contributions (not deducted from pay)') }}</h2>
            <table>
                <tbody>
                @foreach ($employer as $line)
                    <tr><td>{{ $line['name'] }}</td><td class="num">{{ $line['amount'] }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </section>
    @endif

    @if ($note)
        <section class="block"><h2>{{ __('Notes') }}</h2><p>{{ $note }}</p></section>
    @endif
</main>
@if (! ($embedded ?? false))
    <script @if ($nonce = \Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ $nonce }}" @endif>
        document.getElementById('print-document').addEventListener('click', function () { window.print(); });
    </script>
@endif
</body>
</html>
