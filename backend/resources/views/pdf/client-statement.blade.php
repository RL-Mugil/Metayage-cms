<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  @page { margin: 92px 30px 78px 30px; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 8.4px; color: #1f2937; }
  header { position: fixed; top: -78px; left: 0; right: 0; height: 60px; text-align: center; }
  header img { height: 44px; }
  footer { position: fixed; bottom: -64px; left: 0; right: 0; height: 56px; text-align: center; font-size: 8px; color: #374151; border-top: 1px solid #d1d5db; padding-top: 5px; }
  footer .co { color: #15803d; font-weight: bold; font-size: 10px; }
  footer .page:after { content: "Page " counter(page); }
  h1 { font-size: 15px; margin: 0 0 2px; }
  h2 { font-size: 10px; margin: 14px 0 4px; }
  .muted { color: #6b7280; }
  .tiles { width: 100%; border-collapse: separate; border-spacing: 5px; margin: 8px -5px 6px; }
  .tile { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 6px; padding: 6px 7px; }
  .tile b { display: block; font-size: 11px; }
  .tile span { font-size: 7px; text-transform: uppercase; color: #6b7280; }
  .tile.due b { color: #b91c1c; }
  .tile.cr b { color: #15803d; }
  table.data { width: 100%; border-collapse: collapse; }
  table.data th { background: #1e3a5f; color: #fff; font-size: 7.2px; text-transform: uppercase; padding: 4px 3px; text-align: left; }
  table.data td { padding: 3px 3px; border-bottom: 1px solid #eef2f7; vertical-align: top; word-wrap: break-word; }
  table.data tr:nth-child(even) td { background: #f9fafb; }
  table.data tr.total td { font-weight: bold; border-top: 1px solid #1e3a5f; background: #fff; }
  .r { text-align: right; white-space: nowrap; }
  .tds { color: #92400e; }
  .adv { color: #15803d; }
</style>
</head>
<body>
@php
  $money = fn ($v) => (float) $v != 0.0 ? number_format((float) $v, 2) : '—';
  $credit = round((float) $lines->sum(fn ($l) => (float) $l->credit), 2);
@endphp
<header>@if ($letterhead['logo_data_uri'])<img src="{{ $letterhead['logo_data_uri'] }}" alt="">@endif</header>
<footer>
  <div class="co">{{ $letterhead['company_name'] }}</div>
  <div>{{ $letterhead['address'] }}</div>
  <div>☎ {{ $letterhead['phone'] }} | Web: {{ $letterhead['web'] }} &nbsp;·&nbsp; <span class="page"></span></div>
</footer>

<h1>{{ $title }}</h1>
<div><b>{{ $clientName }}</b> ({{ $client->client_code }})</div>
<div class="muted">As on {{ now()->format('d M Y') }} · amounts in INR</div>

<table class="tiles"><tr>
  <td class="tile"><b>{{ number_format($summary['billed'], 2) }}</b><span>Invoiced</span></td>
  <td class="tile"><b>{{ number_format($summary['received'], 2) }}</b><span>Payments received</span></td>
  <td class="tile"><b>{{ number_format($summary['tds'], 2) }}</b><span>TDS withheld</span></td>
  @if ($summary['credit_notes'] > 0)<td class="tile"><b>{{ number_format($summary['credit_notes'], 2) }}</b><span>Credit notes</span></td>@endif
  @if ($summary['advance'] > 0)<td class="tile"><b>{{ number_format($summary['advance'], 2) }}</b><span>Balance credit</span></td>@endif
  <td class="tile {{ $summary['balance'] < -0.009 ? 'cr' : ($summary['balance'] > 0.009 ? 'due' : '') }}"><b>{{ number_format(abs($summary['balance']), 2) }}</b><span>{{ $summary['balance'] < -0.009 ? 'Credit with us' : 'Balance due' }}</span></td>
</tr></table>

<table class="data">
  <tr>
    <th>Date</th><th>Type</th><th>Reference</th><th class="r">Debit</th><th class="r">Credit</th><th class="r">TDS</th><th class="r">Balance</th>
    @if ($internal)<th>Notes</th>@endif
  </tr>
  @forelse ($lines as $l)
    <tr>
      <td>{{ $l->transaction_date?->format('d-m-Y') }}</td>
      <td class="{{ $l->document_type === 'TDS' ? 'tds' : (in_array($l->document_type, ['Balance credit', 'Advance'], true) ? 'adv' : '') }}">{{ $l->document_type }}</td>
      <td>{{ $l->document_reference }}</td>
      <td class="r">{{ $money($l->debit) }}</td>
      <td class="r">{{ $money($l->credit) }}</td>
      <td class="r tds">{{ $money($l->tds) }}</td>
      <td class="r">{{ \App\Services\ClientStatement::balanceText((float) $l->balance) }}</td>
      @if ($internal)<td class="muted">{{ $l->notes }}</td>@endif
    </tr>
  @empty
    <tr><td colspan="{{ $internal ? 8 : 7 }}" class="muted">No transactions.</td></tr>
  @endforelse
  <tr class="total">
    <td></td><td></td><td>Total</td>
    <td class="r">{{ number_format($summary['billed'], 2) }}</td>
    <td class="r">{{ number_format($credit, 2) }}</td>
    <td class="r">{{ number_format($summary['tds'], 2) }}</td>
    <td class="r">{{ \App\Services\ClientStatement::balanceText($summary['balance']) }}</td>
    @if ($internal)<td></td>@endif
  </tr>
</table>
<div class="muted" style="margin-top:4px">TDS is tax withheld by you on our invoices and is shown separately from payments. "Cr" is credit held with us.</div>

@if ($withProformas ?? $internal)
  <h2>Proformas (not yet invoiced — not included in the balance)</h2>
  <table class="data">
    <tr><th>Date</th><th>Proforma</th><th>Status</th><th class="r">Amount</th><th class="r">Balance credit earmarked</th><th class="r">Net due</th><th>Title</th></tr>
    @forelse ($quotes as $q)
      <tr><td>{{ $q['date'] }}</td><td>{{ $q['number'] }}</td><td>{{ $q['status'] }}</td><td class="r">{{ number_format($q['amount'], 2) }}</td><td class="r adv">{{ $q['advance_linked'] > 0 ? number_format($q['advance_linked'], 2).' ('.implode(', ', $q['advance_payments']).')' : '—' }}</td><td class="r">{{ number_format($q['net_due'], 2) }}</td><td>{{ $q['title'] }}</td></tr>
    @empty
      <tr><td colspan="7" class="muted">No proformas pending.</td></tr>
    @endforelse
    @if (count($quotes))
      <tr class="total"><td></td><td>Total proformas</td><td></td><td class="r">{{ number_format(collect($quotes)->sum('amount'), 2) }}</td><td></td><td class="r">{{ number_format($summary['quoted'], 2) }}</td><td></td></tr>
    @endif
  </table>
  <div class="muted" style="margin-top:4px">A proforma becomes a tax invoice once paid; it is not part of the balance above.</div>
@endif
</body>
</html>
