<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Renewal Invoice</title>
  <style>
    body { margin: 0; padding: 0; background: #f4f4f5; font-family: Inter, Arial, sans-serif; color: #18181b; }
    .wrap { max-width: 560px; margin: 40px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
    .header { background: #09090b; padding: 32px 40px 24px; text-align: center; }
    .logo { display: inline-flex; align-items: center; justify-content: center; width: 48px; height: 48px; border-radius: 10px; background: linear-gradient(135deg, #3b82f6, #eab308); color: #fff; font-size: 20px; font-weight: 700; margin-bottom: 16px; }
    .header h1 { margin: 0; color: #ffffff; font-size: 22px; font-weight: 600; }
    .header p { margin: 6px 0 0; color: #a1a1aa; font-size: 13px; }
    .body { padding: 32px 40px; }
    .body p { margin: 0 0 16px; font-size: 15px; line-height: 1.6; color: #3f3f46; }
    .invoice { background: #fafafa; border: 1px solid #e4e4e7; border-radius: 8px; padding: 20px 24px; margin: 24px 0; }
    .invoice table { width: 100%; border-collapse: collapse; }
    .invoice td { padding: 6px 0; font-size: 14px; }
    .invoice td:first-child { color: #71717a; width: 140px; }
    .invoice td:last-child { font-family: 'Courier New', monospace; font-weight: 600; color: #18181b; text-align: right; }
    .total td { border-top: 1px solid #e4e4e7; padding-top: 12px; font-size: 16px; }
    .cta { text-align: center; margin: 28px 0 8px; }
    .btn { display: inline-block; background: #eab308; color: #09090b; text-decoration: none; font-weight: 700; font-size: 15px; padding: 13px 32px; border-radius: 8px; }
    .footer { border-top: 1px solid #f4f4f5; padding: 20px 40px; text-align: center; font-size: 12px; color: #a1a1aa; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="header">
      <div class="logo">IP</div>
      <h1>Renewal Invoice Raised</h1>
      <p>{{ $invoice->docket_number }}</p>
    </div>
    <div class="body">
      <p>Hi <strong>{{ $clientName }}</strong>,</p>
      <p>Following your approval, we've raised the renewal invoice below. Please arrange payment and upload your payment reference in the portal's Pending Payments page.</p>

      <div class="invoice">
        <table>
          <tr>
            <td>Invoice #</td>
            <td>{{ $invoice->invoice_uin }}</td>
          </tr>
          <tr>
            <td>Case</td>
            <td>{{ $invoice->docket_number }}</td>
          </tr>
          <tr>
            <td>Government Fee</td>
            <td>{{ $invoice->currency }} {{ number_format((float) $invoice->patent_office_fees, 2) }}</td>
          </tr>
          <tr>
            <td>Professional Fee</td>
            <td>{{ $invoice->currency }} {{ number_format((float) $invoice->service_fees, 2) }}</td>
          </tr>
          <tr class="total">
            <td>Total Payable</td>
            <td>{{ $invoice->currency }} {{ number_format((float) $invoice->invoice_amount, 2) }}</td>
          </tr>
        </table>
      </div>

      <div class="cta">
        <a href="{{ $portalUrl }}" class="btn">View in Portal</a>
      </div>
    </div>
    <div class="footer">
      &copy; {{ date('Y') }} Metayage / MYPL &mdash; MyIPStrategy Platform
    </div>
  </div>
</body>
</html>
