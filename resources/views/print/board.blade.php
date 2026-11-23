<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Board {{ $kind }}</title>
    <style>body{font-family:Arial,sans-serif;color:#111;margin:24mm 18mm}h1{font-size:22px;border-bottom:2px solid #222;padding-bottom:8px}h2{font-size:16px;margin-top:22px}table{width:100%;border-collapse:collapse}th,td{border-bottom:1px solid #bbb;padding:7px;text-align:left;font-size:12px}.private{font-size:11px;color:#555}@media print{@page{size:A4;margin:15mm}button{display:none}}</style>
</head>
<body>
<p class="private">Private Board Management document · authorized association use only</p>
<h1>{{ $association->name ?? 'Association' }} — {{ ucfirst($kind) }}</h1>
@if($meeting)
<p><strong>{{ $meeting->reference }}</strong> · {{ $meeting->title }} · {{ $meeting->starts_at }}</p>
@endif
@if($kind === 'directory')
<h2>Appointments</h2>
<table><tr><th>Position</th><th>Member</th><th>Status</th><th>Term dates</th></tr>
@foreach($appointments as $row)
<tr><td>{{ $row->position_title }}</td><td>{{ $row->member_name ?? $row->external_name ?? 'Vacant' }}</td><td>{{ $row->status }}</td><td>{{ $row->starts_on }} — {{ $row->ends_on ?? 'Open' }}</td></tr>
@endforeach
</table>
@elseif($kind === 'attendance')
<h2>Attendance</h2>
<table><tr><th>Participant</th><th>Invitation</th><th>Attendance</th><th>Mode</th></tr>
@foreach($participants as $row)
<tr><td>{{ $row->guest_name ?? ('Participant '.$row->id) }}</td><td>{{ $row->invitation_status }}</td><td>{{ $row->attendance_status }}</td><td>{{ $row->attendance_mode }}</td></tr>
@endforeach
</table>
@elseif($kind === 'minutes')
<h2>Approved minutes</h2>
<pre style="white-space:pre-wrap;font:12px Arial">{{ $minutes ? json_encode(json_decode($minutes->redacted_content ?: $minutes->content, true), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) : 'No approved minutes.' }}</pre>
@elseif($kind === 'handover')
<h2>Appointment history</h2>
<table><tr><th>Appointment</th><th>Action</th><th>Effective</th><th>Reason</th></tr>
@foreach($history as $row)
<tr><td>{{ $row->appointment_id }}</td><td>{{ $row->action }}</td><td>{{ $row->effective_on }}</td><td>{{ $row->reason }}</td></tr>
@endforeach
</table>
@else
<h2>Agenda</h2>
<ol>
@foreach($agenda as $row)
<li><strong>{{ $row->subject }}</strong><p>{{ $row->motion_text }}</p></li>
@endforeach
</ol>
@endif
</body>
</html>
