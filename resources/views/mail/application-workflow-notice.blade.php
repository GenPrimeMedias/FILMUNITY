@include('mail.association-brand-header')
<h1>{{ $notice['heading'] }}</h1><p style="white-space:pre-wrap">{{ $notice['body'] }}</p>
<p><a href="{{ url('/membership-applications') }}">My Memberships</a> · <a href="{{ url('/association-membership-review') }}">Association review workspace</a></p>
