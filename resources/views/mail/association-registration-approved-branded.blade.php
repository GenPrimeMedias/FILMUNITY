@include('mail.association-brand-header')
<h1>Association registration approved</h1>
<p>FILMUNITY has approved the association registration for {{ $associationName }}.</p>
<p>Application reference {{ $applicationReference }}</p>
<p><a href="{{ url('/association-workspaces') }}">Open the association workspace in FILMUNITY</a></p>
