<!doctype html>
<html><body style="margin:0;background:#f4f4f5;font-family:Arial,sans-serif;color:#18181b">
<div style="max-width:760px;margin:0 auto;padding:24px">
  <div style="background:#111827;color:white;padding:20px;border-radius:10px 10px 0 0"><div style="font-size:12px;color:#fbbf24;text-transform:uppercase;letter-spacing:1px">MyIPStrategy Portal</div><h1 style="font-size:22px;margin:6px 0 0">{{ $profileName }}</h1></div>
  <div style="background:white;padding:18px;border-radius:0 0 10px 10px">
    <p style="margin-top:0;font-size:13px;color:#52525b">{{ $counts['actionable'] }} actionable docket items: {{ $counts['overdue'] }} overdue, {{ $counts['red'] }} due within 7 days, and {{ $counts['amber'] }} due within 30 days.</p>
    <table role="presentation" style="width:100%;border-collapse:collapse;font-size:12px">
      <thead><tr style="text-align:left;background:#f4f4f5"><th style="padding:9px">Due</th><th style="padding:9px">Band</th><th style="padding:9px">Docket / asset</th><th style="padding:9px">Action</th><th style="padding:9px">Client</th></tr></thead>
      <tbody>@foreach($items as $item)<tr style="border-top:1px solid #e4e4e7"><td style="padding:9px;white-space:nowrap">{{ $item['statutory_due_date'] }}</td><td style="padding:9px;font-weight:bold;color:{{ in_array($item['band'], ['overdue','red']) ? '#dc2626' : ($item['band'] === 'amber' ? '#d97706' : '#15803d') }}">{{ strtoupper($item['band']) }}</td><td style="padding:9px">{{ $item['project']['docket_number'] ?? $item['record']['code'] ?? '—' }}</td><td style="padding:9px">{{ $item['title'] }}</td><td style="padding:9px">{{ $item['client']['name'] ?? '—' }}</td></tr>@endforeach</tbody>
    </table>
    <p style="margin:18px 0 0;font-size:11px;color:#71717a">MyIPStrategy is the authoritative source. Complete or amend docket entries inside the portal, not from your calendar or email client.</p>
  </div>
</div></body></html>
