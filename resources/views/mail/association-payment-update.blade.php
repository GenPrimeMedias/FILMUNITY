@include('mail.association-brand-header')
<h1>{{ $title }}</h1>
<p>{{ $messageText }}</p>
<p>Payment reference {{ $paymentId }}</p>
<p><a href="{{ url('/membership-payments/'.$paymentId) }}">Open payment details in FILMUNITY</a></p>
