<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  @page { margin: 92px 34px 78px 34px; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 9.2px; color: #1f2937; }
  header { position: fixed; top: -78px; left: 0; right: 0; height: 60px; text-align: center; }
  header img { height: 44px; }
  footer { position: fixed; bottom: -64px; left: 0; right: 0; height: 56px; text-align: center; font-size: 8.2px; color: #374151; border-top: 1px solid #d1d5db; padding-top: 5px; }
  footer .co { color: #15803d; font-weight: bold; font-size: 10px; }
  footer .page:after { content: "Page " counter(page); }
  h1 { font-size: 15px; margin: 0 0 2px; }
  .muted { color: #6b7280; }
  .tiles { width: 100%; border-collapse: separate; border-spacing: 5px; margin: 8px -5px 4px; }
  .tile { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 6px; padding: 6px 7px; }
  .tile b { display: block; font-size: 12.5px; }
  .tile span { font-size: 7.5px; text-transform: uppercase; color: #6b7280; letter-spacing: .3px; }
  h2 { font-size: 11px; margin: 12px 0 4px; padding-bottom: 2px; border-bottom: 1.5px solid #1e3a5f; color: #1e3a5f; }
  table.data { width: 100%; border-collapse: collapse; }
  table.data th { background: #1e3a5f; color: #fff; font-size: 7.8px; text-transform: uppercase; padding: 4px 4px; text-align: left; }
  table.data td { padding: 3.5px 4px; border-bottom: 1px solid #eef2f7; }
  table.data tr:nth-child(even) td { background: #f9fafb; }
  .r { text-align: right; white-space: nowrap; }
  .pill { display: inline-block; padding: 1px 5px; border-radius: 8px; font-size: 7.5px; font-weight: bold; }
  .od { background: #fee2e2; color: #b91c1c; } .nd { background: #dbeafe; color: #1d4ed8; } .pd { background: #dcfce7; color: #15803d; } .wv { background: #ede9fe; color: #6d28d9; }
  .bar { height: 9px; background: #3b82f6; display: inline-block; vertical-align: middle; border-radius: 2px; }
  .bar.c { background: #10b981; }
  .chart td { padding: 2px 3px; font-size: 8px; vertical-align: middle; }
  .sig { margin-top: 14px; border: 1px solid #a7f3d0; background: #ecfdf5; border-radius: 6px; padding: 8px 10px; }
  .sig.uns { border-color: #fecaca; background: #fef2f2; }
  .split td { vertical-align: top; }
</style>
</head>
<body>
@php
  $inr = fn ($v) => '₹'.number_format((float) $v, 2);
  $s = $data['summary'];
  $maxM = max(1, collect($data['monthly'])->max(fn ($m) => max($m['billed'], $m['collected'])));
  $maxA = max(1, collect($data['ageing_totals'])->max('amount'));
  $pill = fn ($st) => ['Overdue' => 'od', 'Pending' => 'nd', 'Paid' => 'pd', 'Waived' => 'wv'][$st] ?? 'nd';
@endphp
<header>@if ($letterhead['logo_data_uri'])<img src="{{ $letterhead['logo_data_uri'] }}" alt="">@endif</header>
<footer>
  <div class="co">{{ $letterhead['company_name'] }}</div>
  <div>{{ $letterhead['address'] }}</div>
  <div>☎ {{ $letterhead['phone'] }} | Web: {{ $letterhead['web'] }} &nbsp;·&nbsp; <span class="page"></span></div>
</footer>

<h1>{{ $title }}</h1>
<div class="muted">
  Period: {{ ($data['filters']['from'] || $data['filters']['to']) ? (($data['filters']['from'] ?? 'start').' to '.($data['filters']['to'] ?? 'today')) : 'All dates' }}
  · Client: {{ $data['filters']['client'] }} · Generated {{ now()->format('d M Y, H:i') }}
</div>

<table class="tiles"><tr>
  <td class="tile"><b>{{ $inr($s['billed']) }}</b><span>Billed · {{ $s['invoices'] }} invoices</span></td>
  <td class="tile"><b style="color:#047857">{{ $inr($s['received']) }}</b><span>Received · {{ $s['collection_rate'] }}%</span></td>
  <td class="tile"><b style="color:#b45309">{{ $inr($s['outstanding']) }}</b><span>Outstanding · {{ $s['pending_invoices'] }} invoices</span></td>
  <td class="tile"><b style="color:#b91c1c">{{ $inr($s['overdue_amount']) }}</b><span>Overdue · {{ $s['overdue_invoices'] }} invoices</span></td>
  <td class="tile"><b style="color:#6d28d9">{{ $inr($s['waived']) }}</b><span>Waived</span></td>
</tr></table>

<table class="split" width="100%"><tr>
  <td width="58%">
    <h2>Billed vs collected — 12 months</h2>
    <table class="chart" width="100%">
      @foreach ($data['monthly'] as $m)
        <tr>
          <td width="14%" class="muted">{{ $m['month'] }}</td>
          <td><span class="bar" style="width: {{ max(1, round($m['billed'] / $maxM * 150)) }}px"></span> <span class="muted">{{ $inr($m['billed']) }}</span><br>
              <span class="bar c" style="width: {{ max(1, round($m['collected'] / $maxM * 150)) }}px"></span> <span class="muted">{{ $inr($m['collected']) }}</span></td>
        </tr>
      @endforeach
    </table>
    <div class="muted" style="margin-top:3px"><span class="bar" style="width:10px"></span> Billed &nbsp; <span class="bar c" style="width:10px"></span> Collected</div>
  </td>
  <td width="42%" style="padding-left:12px">
    <h2>Outstanding by age</h2>
    <table class="chart" width="100%">
      @foreach ($data['ageing_totals'] as $a)
        <tr><td width="34%">{{ $a['band'] }}</td>
            <td><span class="bar" style="background:#f59e0b; width: {{ max(1, round($a['amount'] / $maxA * 110)) }}px"></span> {{ $inr($a['amount']) }} ({{ $a['count'] }})</td></tr>
      @endforeach
    </table>
  </td>
</tr></table>

<h2>Follow-up — pending invoices ({{ count($data['pending']) }})</h2>
<table class="data">
  <tr><th>Invoice</th><th>Client</th><th>Contact</th><th>Due</th><th class="r">Days</th><th>Status</th><th class="r">Billed</th><th class="r">Balance</th></tr>
  @forelse ($data['pending'] as $r)
    <tr><td>{{ $r['invoice'] }}</td><td>{{ $r['client'] }}</td><td>{{ $r['contact'] }}{{ $r['phone'] ? ' · '.$r['phone'] : '' }}</td>
        <td>{{ $r['due_date'] }}</td><td class="r">{{ $r['days_overdue'] ?: '' }}</td><td><span class="pill {{ $pill($r['status']) }}">{{ $r['status'] }}</span></td>
        <td class="r">{{ $inr($r['billed']) }}</td><td class="r"><b>{{ $inr($r['balance']) }}</b></td></tr>
  @empty <tr><td colspan="8" class="muted">Nothing pending.</td></tr> @endforelse
</table>

<h2>Payments received ({{ count($data['payments']) }})</h2>
<table class="data">
  <tr><th>Date</th><th>Invoice</th><th>Client</th><th>Mode</th><th>Recorded</th><th class="r">Amount</th></tr>
  @forelse ($data['payments'] as $p)
    <tr><td>{{ $p['date'] }}</td><td>{{ $p['invoice'] }}</td><td>{{ $p['client'] }}</td><td>{{ $p['mode'] }}</td><td>{{ $p['source'] }}</td><td class="r">{{ $inr($p['amount']) }}</td></tr>
  @empty <tr><td colspan="6" class="muted">No payments in this period.</td></tr> @endforelse
</table>

@if (count($data['waivers']))
<h2>Waivers ({{ count($data['waivers']) }})</h2>
<table class="data">
  <tr><th>Date</th><th>Invoice</th><th>Client</th><th>Reason</th><th>Approved by</th><th class="r">Amount</th></tr>
  @foreach ($data['waivers'] as $w)
    <tr><td>{{ $w['date'] }}</td><td>{{ $w['invoice'] }}</td><td>{{ $w['client'] }}</td><td>{{ $w['reason'] }}</td><td>{{ $w['approved_by'] }}</td><td class="r">{{ $inr($w['amount']) }}</td></tr>
  @endforeach
</table>
@endif

<h2>By client</h2>
<table class="data">
  <tr><th>Client</th><th class="r">Invoices</th><th class="r">Billed</th><th class="r">Received</th><th class="r">Waived</th><th class="r">Outstanding</th></tr>
  @foreach (array_slice($data['by_client'], 0, 40) as $c)
    <tr><td>{{ $c['client'] }} <span class="muted">{{ $c['client_code'] }}</span></td><td class="r">{{ $c['invoices'] }}</td><td class="r">{{ $inr($c['billed']) }}</td>
        <td class="r">{{ $inr($c['received']) }}</td><td class="r">{{ $inr($c['waived']) }}</td><td class="r"><b>{{ $inr($c['outstanding']) }}</b></td></tr>
  @endforeach
</table>

@if ($signature)
  <div class="sig">
    <b>Digitally signed</b> by {{ $signature['name'] }} ({{ $signature['role'] }}) on {{ $signature['at'] }}<br>
    Verification code <b>{{ $signature['code'] }}</b> · verify at {{ $signature['url'] }}<br>
    <span class="muted">Internal approval signature (tamper-evident). Any change to this report's data invalidates the code.</span>
  </div>
@else
  <div class="sig uns"><b>UNSIGNED DRAFT</b> — generated without a signature PIN.</div>
@endif
</body>
</html>
