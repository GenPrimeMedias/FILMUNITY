<svg xmlns="http://www.w3.org/2000/svg" width="860" height="540" viewBox="0 0 860 540" role="img" aria-label="Digital membership card">
<rect x="2" y="2" width="856" height="536" rx="20" fill="#1b251b" stroke="{{ $card['accent'] }}" stroke-width="4"/>
<rect x="18" y="18" width="824" height="5" rx="2" fill="{{ $card['accent'] }}"/>
@if($card['logo'])<image href="{{ $card['logo'] }}" x="28" y="36" width="56" height="56"/>@endif
<text x="{{ $card['logo'] ? 102 : 28 }}" y="66" fill="#eef3df" font-family="Arial,sans-serif" font-size="25">{{ mb_strimwidth($card['issuer'],0,52,'…') }}</text>
<text x="28" y="109" fill="#bec9ad" font-family="Arial,sans-serif" font-size="18">Digital membership card</text>
<rect x="28" y="143" width="132" height="176" rx="8" fill="#30412c"/>
@if($card['photo'])<image href="{{ $card['photo'] }}" x="28" y="143" width="132" height="176" preserveAspectRatio="xMidYMid slice"/>@endif
<g fill="#eef3df" font-family="Arial,sans-serif"><text x="182" y="169" font-size="28">{{ mb_strimwidth($card['name'],0,28,'…') }}</text><text x="182" y="211" font-size="25">{{ $card['number'] }}</text><text x="182" y="253" font-size="20">{{ mb_strimwidth($card['type'],0,32,'…') }}</text><text x="182" y="295" font-size="20">Status: {{ $card['status'] }}</text><text x="28" y="371" font-size="20">Member since: {{ \Carbon\CarbonImmutable::parse($card['joined'])->format('d M Y') }}</text><text x="28" y="410" font-size="20">Valid until: {{ $card['expires'] ? \Carbon\CarbonImmutable::parse($card['expires'])->format('d M Y') : 'No scheduled expiry' }}</text><text x="28" y="492" font-size="16">Scan to verify current status. Membership grants no administrative privileges.</text></g>
<rect x="616" y="143" width="216" height="216" rx="8" fill="white"/><image href="{{ $card['qr'] }}" x="622" y="149" width="204" height="204"/>
</svg>
