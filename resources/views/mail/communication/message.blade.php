<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0;padding:24px;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;font-size:15px;line-height:1.6;color:#1f2937;">
<div style="max-width:600px;margin:0 auto;">
{!! nl2br(e($body)) !!}
@if ($footer)
<hr style="border:0;border-top:1px solid #e5e7eb;margin:24px 0;">
<div style="font-size:12px;color:#6b7280;">{!! nl2br(e($footer)) !!}</div>
@endif
@if ($unsubscribeUrl)
<p style="font-size:12px;color:#6b7280;"><a href="{{ $unsubscribeUrl }}">{{ __('Unsubscribe') }}</a></p>
@endif
</div>
</body>
</html>
