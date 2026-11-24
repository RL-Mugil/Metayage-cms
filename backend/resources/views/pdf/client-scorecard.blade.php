<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  @page { margin: 28px 34px; }
  body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #1f2937; }
  h1 { font-size: 18px; margin: 0; }
  .muted { color: #6b7280; }
  .tiles { width: 100%; border-collapse: separate; border-spacing: 6px; margin: 14px -6px 6px; }
  .tile { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 6px; padding: 8px; text-align: center; }
  .tile b { display: block; font-size: 16px; margin-bottom: 2px; }
  h2 { font-size: 12px; margin: 16px 0 6px; border-bottom: 1px solid #e5e7eb; padding-bottom: 3px; }
  table.list { width: 100%; border-collapse: collapse; }
  table.list td, table.list th { padding: 4px 6px; border-bottom: 1px solid #f1f5f9; text-align: left; }
  table.list th { font-size: 9px; text-transform: uppercase; color: #6b7280; }
  .red { color: #b91c1c; } .green { color: #047857; } .amber { color: #b45309; }
  .right { text-align: right; }
</style>
</head>
<body>
  <table width="100%"><tr>
    <td><h1>Monthly scorecard</h1><div class="muted">{{ $card['client'] }} ({{ $card['client_code'] }}) · {{ $card['month'] }}</div></td>
    <td class="right muted">Generated {{ $card['generated'] }}</td>
  </tr></table>

  <table class="tiles"><tr>
    <td class="tile"><b>{{ $card['cases']['active'] }}</b>Active cases</td>
    <td class="tile"><b>{{ $card['cases']['opened'] }}</b>Opened this month</td>
    <td class="tile"><b class="green">{{ $card['deadlines']['on_time'] }}/{{ $card['deadlines']['met'] }}</b>Deadlines met on time</td>
    <td class="tile"><b class="{{ $card['deadlines']['open_overdue'] ? 'red' : '' }}">{{ $card['deadlines']['open_overdue'] }}</b>Deadlines overdue</td>
    <td class="tile"><b>{{ $card['renewals_total'] }}</b>Renewals in 90 days</td>
  </tr></table>

  <h2>Money</h2>
  <table class="list">
    <tr><td>Billed this month</td><td class="right">₹{{ number_format($card['money']['billed_month']) }}</td>
        <td>Paid this month</td><td class="right green">₹{{ number_format($card['money']['paid_month']) }}</td></tr>
    <tr><td>Outstanding ({{ $card['money']['open_invoices'] }} invoices)</td><td class="right amber">₹{{ number_format($card['money']['outstanding']) }}</td>
        <td>Of which overdue</td><td class="right {{ $card['money']['overdue'] ? 'red' : '' }}">₹{{ number_format($card['money']['overdue']) }}</td></tr>
  </table>

  <h2>Coming deadlines</h2>
  @if (count($card['deadlines']['next']))
    <table class="list"><tr><th>Case</th><th>Deadline</th><th class="right">Due</th></tr>
      @foreach ($card['deadlines']['next'] as $d)
        <tr><td>{{ $d['docket'] }}</td><td>{{ $d['title'] }}</td><td class="right">{{ $d['due'] }}</td></tr>
      @endforeach
    </table>
  @else <p class="muted">No open deadlines.</p> @endif

  <h2>Renewals ahead</h2>
  @if (count($card['renewals']))
    <table class="list"><tr><th>Case</th><th>Fee</th><th>Due</th><th class="right">Estimate</th></tr>
      @foreach ($card['renewals'] as $r)
        <tr><td>{{ $r['docket'] }}</td><td>{{ $r['label'] }}</td><td class="{{ $r['overdue'] ? 'red' : '' }}">{{ $r['due'] }}{{ $r['overdue'] ? ' (overdue)' : '' }}</td><td class="right">{{ $r['estimate'] }}</td></tr>
      @endforeach
    </table>
  @else <p class="muted">No renewals in the next 90 days.</p> @endif

  <p class="muted" style="margin-top:18px;">Estimates use the firm's standard fee card and may change with official fee revisions or exchange rates. Questions? Reply from your portal on any item.</p>
</body>
</html>
