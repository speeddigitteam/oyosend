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
        .badge.overdue, .badge.void { border-color: #9c2a1f; color: #9c2a1f; }
        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin: 28px 0 20px; }
        .parties h2, .meta h2 { margin: 0 0 4px; font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: #55595f; }
        .parties p { margin: 0; }
        .meta { display: flex; flex-wrap: wrap; gap: 8px 32px; margin-bottom: 20px; }
        .meta div p { margin: 0; font-weight: 600; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: start; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #55595f; border-bottom: 2px solid #1f2328; padding: 6px 8px; }
        td { padding: 8px; border-bottom: 1px solid #dcd8d0; vertical-align: top; }
        .num { text-align: end; white-space: nowrap; }
        .line-desc { color: #55595f; font-size: 12px; white-space: pre-line; }
        .totals { margin: 20px 0 0 auto; width: 60%; }
        .totals td { border: 0; padding: 4px 8px; }
        .totals tr.strong td { border-top: 2px solid #1f2328; font-weight: 700; font-size: 15px; }
        .block { margin-top: 24px; }
        .block h2 { margin: 0 0 4px; font-size: 11px; text-transform: uppercase; letter-spacing: .08em; color: #55595f; }
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
            .parties { grid-template-columns: 1fr; }
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
            @if ($status)
                <span class="badge {{ $statusKey }}">{{ $status }}</span>
            @endif
        </div>
    </header>

    <section class="parties">
        <div>
            <h2>{{ __('Bill to') }}</h2>
            <p><strong>{{ $customer['name'] }}</strong></p>
            @foreach ($customer['lines'] as $line)
                <p class="muted">{{ $line }}</p>
            @endforeach
        </div>
        <div class="meta">
            @foreach ($dates as $date)
                <div><h2>{{ $date['label'] }}</h2><p>{{ $date['value'] }}</p></div>
            @endforeach
            @if ($reference)
                <div><h2>{{ $kind === 'credit_note' ? __('Against invoice') : __('Reference') }}</h2><p>{{ $reference }}</p></div>
            @endif
        </div>
    </section>

    @if ($subject)
        <p><strong>{{ $kind === 'credit_note' ? __('Reason') : __('Subject') }}:</strong> {{ $subject }}</p>
    @endif

    <table>
        <thead>
        <tr>
            <th>{{ __('Description') }}</th>
            <th class="num">{{ __('Quantity') }}</th>
            <th class="num">{{ __('Unit price') }}</th>
            <th class="num">{{ __('Tax') }}</th>
            <th class="num">{{ __('Amount') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($lines as $line)
            <tr>
                <td>
                    {{ $line['name'] }}
                    @if ($line['description'])
                        <div class="line-desc">{{ $line['description'] }}</div>
                    @endif
                    @if ($line['discount'])
                        <div class="line-desc">{{ __('Discount') }}: {{ $line['discount'] }}</div>
                    @endif
                </td>
                <td class="num">{{ $line['quantity'] }}</td>
                <td class="num">{{ $line['unitPrice'] }}</td>
                <td class="num">{{ $line['tax'] ?? '-' }}</td>
                <td class="num">{{ $line['total'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals">
        @foreach ($totals as $row)
            <tr class="{{ ($row['strong'] ?? false) ? 'strong' : '' }}">
                <td>{{ $row['label'] }}</td>
                <td class="num">{{ $row['value'] }}</td>
            </tr>
        @endforeach
    </table>

    @if ($notes)
        <section class="block"><h2>{{ __('Notes') }}</h2><p>{{ $notes }}</p></section>
    @endif
    @if ($terms)
        <section class="block"><h2>{{ __('Terms') }}</h2><p>{{ $terms }}</p></section>
    @endif
</main>
@if (! ($embedded ?? false))
    <script @if ($nonce = \Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ $nonce }}" @endif>
        document.getElementById('print-document').addEventListener('click', function () { window.print(); });
    </script>
@endif
</body>
</html>
