@if(!empty($branding['logo']))
    @php
        $logoParts = explode(';base64,', $branding['logo'], 2);
        $logoBytes = count($logoParts) === 2 ? base64_decode($logoParts[1], true) : false;
        $logoMime = str_replace('data:', '', $logoParts[0]);
    @endphp
    @if($logoBytes !== false && in_array($logoMime, ['image/png', 'image/jpeg', 'image/webp'], true))
        <img src="{{ $message->embedData($logoBytes, 'association-logo', $logoMime) }}" width="72" height="72" alt="Association logo" style="object-fit:contain">
    @endif
@endif
<h2>{{ $branding['association_name'] ?? 'Association' }}</h2>
