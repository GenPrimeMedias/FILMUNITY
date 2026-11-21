<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $title }} — {{ $record->id }}</title>
<link rel="stylesheet" href="/print.css">
<style>
@page { size:A4; margin:14mm; }
body { margin:0; background:#f2f3ee; color:#17200c; font:14px/1.5 Arial,sans-serif; }
main { max-width:182mm; margin:24px auto; padding:24px; background:white; border-top:5px solid #b9d66b; }
table.document { width:100%; border-collapse:collapse; table-layout:fixed; }
.heading img { width:180px; height:40px; object-fit:contain; }
.heading { text-align:left; border-bottom:2px solid #819b46; padding:0 0 12px; }
h1 { font-size:22px; margin:8px 0; } h2 { font-size:16px; }
.answer { padding:10px 0; border-bottom:1px solid #ddd; overflow-wrap:anywhere; }
.answer p, .notes { white-space:pre-wrap; margin:4px 0; }
.office { margin-top:24px; border:1px solid #888; padding:16px; min-height:90px; }
.actions { max-width:182mm; margin:16px auto; } button { padding:10px 16px; cursor:pointer; }
@media print { body { background:white; } main { margin:0; padding:0; border:0; max-width:none; } thead { display:table-header-group; } .document>tbody>tr { break-inside:auto; } .office { break-inside:avoid; } }
</style></head><body>
<div class="actions">@include('print.controls')</div>
<main><table class="document"><thead><tr><th class="heading">
@if($branding['logo'] ?? null)<img src="{{ $branding['logo'] }}" alt="{{ $branding['association_name'] }}">@endif
<div>{{ $branding['association_name'] }}</div><h1>{{ $title }}</h1>
<div>Reference: {{ $record->id }} · Submitted: {{ $record->created_at }}</div>
</th></tr></thead><tbody><tr><td>
<section><h2>Recorded application</h2>
@foreach($answers as $answer)<div class="answer"><strong>{{ $answer['label'] }}</strong>
@if(!empty($answer['description']))<p>{{ $answer['description'] }}</p>@endif
<p>{{ is_array($answer['value']) ? implode(', ', $answer['value']) : (is_bool($answer['value']) ? ($answer['value'] ? 'Yes' : 'No') : ($answer['value'] ?? 'Not supplied')) }}</p></div>@endforeach
</section>
<section><h2>Attachment checklist</h2><ul>@forelse($attachments as $attachment)<li>{{ $attachment }}</li>@empty<li>No retained attachment recorded.</li>@endforelse</ul></section>
<section><h2>Recorded decision</h2><p>Status: {{ $record->status }} · Decision date: {{ $record->reviewed_at ?? 'Not decided' }}</p><p class="notes">{{ $note ?? 'No decision note recorded.' }}</p></section>
<section class="office"><h2>Offline office use only</h2><p>Applicant signature: _____________________ Date: ______________</p><p>Officer signature: _______________________ Date: ______________</p><p>Review notes: __________________________________________________</p></section>
<p>Printing does not approve, verify or change this application. Record any subsequent decision through the authorized application workflow.</p>
</td></tr></tbody></table></main></body></html>
