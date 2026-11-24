<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Verify payslip — {{ $letterhead['company_name'] }}</title>
<style>
  :root { color-scheme: light; }
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: linear-gradient(160deg, #f8fafc, #eef2ff); font-family: Inter, system-ui, -apple-system, Segoe UI, sans-serif; color: #0f172a; padding: 16px; box-sizing: border-box; }
  .card { width: 100%; max-width: 460px; background: #fff; border: 1px solid #e2e8f0; border-radius: 18px; box-shadow: 0 20px 45px -20px rgba(15, 23, 42, .25); overflow: hidden; }
  .head { padding: 22px 24px 14px; text-align: center; border-bottom: 1px solid #f1f5f9; }
  .head img { height: 40px; }
  .status { margin: 18px 24px 6px; padding: 14px 16px; border-radius: 12px; font-weight: 600; display: flex; gap: 10px; align-items: center; }
  .ok { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
  .bad { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
  dl { margin: 10px 24px 22px; display: grid; grid-template-columns: 130px 1fr; gap: 8px 12px; font-size: 14px; }
  dt { color: #64748b; }
  dd { margin: 0; font-weight: 500; overflow-wrap: anywhere; }
  .foot { font-size: 12px; color: #64748b; text-align: center; padding: 12px 24px 18px; border-top: 1px solid #f1f5f9; }
</style>
</head>
<body>
  <div class="card">
    <div class="head">
      @if ($letterhead['logo_data_uri'])<img src="{{ $letterhead['logo_data_uri'] }}" alt="{{ $letterhead['company_name'] }}">@endif
      <div style="margin-top:6px;font-size:13px;color:#64748b">Payslip verification</div>
    </div>
    @if (! $result)
      <div class="status bad">✕ No payslip has the verification code {{ $code }}.</div>
    @else
      <div class="status {{ $result['valid'] ? 'ok' : 'bad' }}">
        {{ $result['valid'] ? '✓ Genuine — this '.strtolower($result['kind']).' is signed and unchanged.' : '✕ Not valid — the '.strtolower($result['kind']).' was changed after it was signed.' }}
      </div>
      <dl>
        <dt>Document</dt><dd>{{ $result['kind'] }}</dd>
        @isset($result['employee'])<dt>Employee</dt><dd>{{ $result['employee'] }}</dd>@endisset
        @isset($result['period'])<dt>Period</dt><dd>{{ $result['period'] }}</dd>@endisset
        @isset($result['net_pay'])<dt>Net pay</dt><dd>₹ {{ number_format($result['net_pay'], 2) }}</dd>@endisset
        <dt>Signed by</dt><dd>{{ $result['signed_by'] }}@if($result['designation']), {{ $result['designation'] }}@endif</dd>
        <dt>Signed on</dt><dd>{{ $result['signed_at'] }}</dd>
        <dt>Code</dt><dd style="font-family:ui-monospace,monospace">{{ $result['code'] }}</dd>
      </dl>
    @endif
    <div class="foot">{{ $letterhead['company_name'] }} · {{ $letterhead['web'] }}<br>Internal digital signature of the issuing company (not an Indian DSC).</div>
  </div>
</body>
</html>
