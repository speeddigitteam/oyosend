{{-- Sent to the company's own inbox, always in English. Every value is the visitor's own text, escaped. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Website enquiry</title>
</head>
<body style="margin:0;padding:24px;background:#ece7df;font-family:Arial,Helvetica,sans-serif;color:#151f1b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:12px;">
        <tr>
            <td style="padding:24px 28px;">
                <h1 style="margin:0 0 16px;font-size:20px;">Website enquiry</h1>
                <table role="presentation" cellpadding="0" cellspacing="0" style="font-size:14px;line-height:1.6;">
                    <tr><td style="padding:2px 16px 2px 0;color:#525e59;">Topic</td><td>{{ $enquiry['topic'] }}</td></tr>
                    <tr><td style="padding:2px 16px 2px 0;color:#525e59;">Name</td><td>{{ $enquiry['name'] }}</td></tr>
                    <tr><td style="padding:2px 16px 2px 0;color:#525e59;">Email</td><td>{{ $enquiry['email'] }}</td></tr>
                    @if ($enquiry['phone'])
                        <tr><td style="padding:2px 16px 2px 0;color:#525e59;">Phone</td><td>{{ $enquiry['phone'] }}</td></tr>
                    @endif
                    @if ($enquiry['company'])
                        <tr><td style="padding:2px 16px 2px 0;color:#525e59;">Company</td><td>{{ $enquiry['company'] }}</td></tr>
                    @endif
                    <tr><td style="padding:2px 16px 2px 0;color:#525e59;">Page language</td><td>{{ $enquiry['locale'] === 'bn' ? 'Bengali' : 'English' }}</td></tr>
                </table>
                <div style="margin-top:16px;padding:16px;background:#f6f3ee;border-radius:8px;font-size:14px;line-height:1.6;white-space:pre-wrap;">{{ $enquiry['message'] }}</div>
                <p style="margin:16px 0 0;font-size:12px;color:#525e59;">Reply to this email to answer the visitor.</p>
            </td>
        </tr>
    </table>
</body>
</html>
