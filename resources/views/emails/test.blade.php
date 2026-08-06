<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ config('app.name') }} mail test</title>
</head>
<body style="font-family: -apple-system, Segoe UI, Helvetica, Arial, sans-serif; line-height: 1.5;">
    <h1 style="font-size: 18px;">Mail is working</h1>
    <p>
        This message was sent by <strong>{{ config('app.name') }}</strong>
        ({{ config('app.env') }}) at {{ $sentAt }} to verify the outbound
        SMTP configuration.
    </p>
    <p style="color: #666; font-size: 13px;">
        Delivered via {{ config('mail.default') }} &mdash;
        {{ config('mail.mailers.smtp.host') }}:{{ config('mail.mailers.smtp.port') }}
    </p>
</body>
</html>
