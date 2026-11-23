@include('mail.association-brand-header')
<h1>Your yearly membership is expiring soon</h1>
<p>Hello {{ $branding['member_name'] }},</p>
<p>Your membership {{ $branding['membership_number'] }} expires on {{ $branding['expires_at'] }}.</p>
<p>You can review the current yearly fee and request renewal in FILMUNITY. This reminder does not create a payment request or extend membership validity.</p>
<p><a href="{{ $branding['url'] }}">Review membership and renewal</a></p>
