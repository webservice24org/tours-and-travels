<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Verify your travel booking request</title></head>
<body style="font-family:Arial,sans-serif;color:#182b3a;line-height:1.6">
    <h1>Bablys Tours &amp; Travels</h1>
    <p>We received a nonbinding booking request with reference <strong>{{ $reference }}</strong>.</p>
    <p>To confirm that you control the email address, open this link and select <strong>Verify email</strong>:</p>
    <p><a href="{{ $verifyUrl }}">Verify your email address</a></p>
    <p>After verification, use your private access link to review or withdraw the request:</p>
    <p><a href="{{ $manageUrl }}">Manage your booking request</a></p>
    <p>These private links must not be forwarded. They do not reserve seats, confirm a booking or authorize payment. Staff will separately review availability and the current price.</p>
    <p>If you did not request this, disregard this email.</p>
</body>
</html>
