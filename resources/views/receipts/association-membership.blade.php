<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $receipt['receipt_number'] }} — Membership receipt</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 32px 16px; background: #101711; color: #eef3df; font-family: Arial, sans-serif; line-height: 1.6; }
        main { position:relative; max-width: 820px; margin: auto; padding: 32px; border: 1px solid #41513b; border-top: 4px solid {{ $receipt['accent'] }}; border-radius: 12px; background: #1b251b; }
        h1 { margin-bottom: 4px; font-size: 26px; } h2 { font-size: 18px; }
        dl { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; } dt { font-size: 13px; color: #becaae; } dd { margin: 0; overflow-wrap: anywhere; }
        .logo { flex:0 0 112px; width: 112px; height: 112px; object-fit: contain; } .address { white-space: pre-wrap; } .amount { font-size: 24px; font-weight: bold; }
        button, a { display: inline-block; padding: 8px 14px; margin: 8px 8px 0 0; border-radius: 6px; border: 1px solid #bbc96d; background: #dce782; color: #172115; text-decoration: none; cursor: pointer; }
        .brand { display:flex; align-items:center; gap:20px; padding-right:32px; }
        .brand h1 { margin:0; } .brand p { margin:4px 0; }
        .section { margin-top:20px; padding:20px; border:1px solid #526348; border-top:3px solid {{ $receipt['accent'] }}; border-radius:10px; break-inside:avoid; }
        .section h2 { margin-top:0; } .section dl { margin-bottom:0; }
        .close { position:absolute; top:12px; right:12px; width:36px; height:36px; padding:0; margin:0; display:grid; place-items:center; background:transparent; color:inherit; font-size:26px; line-height:1; }
        .close:focus-visible { outline:2px solid #dce782; outline-offset:3px; }
        @media(max-width:600px) { .brand {gap:12px;} .logo {flex-basis:80px;width:80px;height:80px;} .brand h1 {font-size:21px;} .section {padding:14px;} }
        @media print { .close {display:none;} .section {border-color:#aaa;} }
        @media(max-width:600px) { main { padding: 20px; } dl { grid-template-columns: 1fr; } }
        @media print { body,main { background: white; color: black; } main { border: 1px solid #ddd; } dt { color: #444; } .actions { display:none; } }
    </style>
<link rel="stylesheet" href="{{ asset('print.css') }}">
</head>
<body>
<main>
    <a class="close" href="{{ url('/membership-payments/'.$receipt['payment_id']) }}" aria-label="Close receipt and return to payment details" title="Close receipt"><span aria-hidden="true">&times;</span></a>
    <header class="brand">
        @if(!empty($receipt['logo']))<img class="logo" src="{{ $receipt['logo'] }}" alt="{{ $receipt['association_name'] }} logo">@endif
        <div>
            <h1>{{ $receipt['association_name'] }}</h1>
            @if($receipt['legal_name'] !== $receipt['association_name'])<p>{{ $receipt['legal_name'] }}</p>@endif
            <p class="address">{{ $receipt['office_address'] }}</p>
            <p>{{ $receipt['official_email'] }}</p>
        </div>
    </header>
    <section class="section" aria-label="Receipt summary">
    <h2>{{ $receipt['amount'] === '0.00' ? 'Zero-fee membership confirmation' : 'Membership payment receipt' }}</h2>
    <p>{{ $receipt['receipt_number'] }}</p>
    <p class="amount">{{ $receipt['currency'] }} {{ $receipt['amount'] }}</p>
    </section>
    <section class="section"><h2>Member and membership</h2>
    <dl>
        <div><dt>Member</dt><dd>{{ $receipt['member_name'] }}</dd></div>
        <div><dt>Email</dt><dd>{{ $receipt['member_email'] }}</dd></div>
        <div><dt>Membership number</dt><dd>{{ $receipt['membership_number'] }}</dd></div>
        @if(!empty($receipt['plan_name']))<div><dt>Membership plan</dt><dd>{{ $receipt['plan_name'] }}</dd></div>@endif
        <div><dt>Membership type</dt><dd>{{ $receipt['membership_type'] === 'yearly' ? 'Yearly' : 'Life-Time' }}</dd></div>
    </dl></section>
    <section class="section"><h2>Payment and acknowledgement</h2><dl>
        <div><dt>Payment reference</dt><dd>{{ $receipt['reference'] }}</dd></div>
        <div><dt>Payment date</dt><dd>{{ $receipt['paid_on'] ?? 'Zero fee' }}</dd></div>
        <div><dt>Issued at (UTC)</dt><dd>{{ $receipt['issued_at'] }}</dd></div>
        <div><dt>Acknowledged by</dt><dd>{{ $receipt['officer'] }}</dd></div>
    </dl></section>
    <section class="section"><h2>Validity and references</h2><dl>
        <div><dt>Valid from</dt><dd>{{ $receipt['starts_at'] }}</dd></div>
        <div><dt>Valid until</dt><dd>{{ $receipt['ends_at'] ?? 'Life-Time' }}</dd></div>
        <div><dt>Application reference</dt><dd>{{ $receipt['application_id'] }}</dd></div>
        <div><dt>Payment request</dt><dd>{{ $receipt['payment_id'] }}</dd></div>
    </dl>
    </section>
    <div class="actions">
        @include('print.button')
        <a href="{{ request()->fullUrlWithQuery(['download' => 1]) }}">Download receipt</a>
        <a href="{{ url('/membership-payments/'.$receipt['payment_id']) }}">Payment details</a>
    </div>
</main>
</body>
</html>
