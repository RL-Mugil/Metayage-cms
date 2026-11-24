<!DOCTYPE html>
<html>
<body style="font-family: Arial, Helvetica, sans-serif; color:#1f2937; background:#f8fafc; margin:0; padding:24px;">
  <div style="max-width:640px; margin:0 auto; background:#ffffff; border:1px solid #e5e7eb; border-radius:10px; padding:24px;">
    <h2 style="margin:0 0 4px; font-size:20px;">Weekly digest</h2>
    <p style="margin:0 0 20px; color:#6b7280; font-size:13px;">Hi {{ $name }}, here is the week ahead ({{ $digest['period'] }}).</p>

    <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
      <tr>
        <td style="padding:10px; background:#fef2f2; border-radius:8px; text-align:center;">
          <div style="font-size:22px; font-weight:bold; color:#b91c1c;">{{ $digest['overdue_deadlines']['count'] }}</div>
          <div style="font-size:11px; color:#6b7280;">Overdue deadlines</div>
        </td>
        <td width="8"></td>
        <td style="padding:10px; background:#fffbeb; border-radius:8px; text-align:center;">
          <div style="font-size:22px; font-weight:bold; color:#b45309;">₹{{ number_format($digest['overdue_payments']['total']) }}</div>
          <div style="font-size:11px; color:#6b7280;">{{ $digest['overdue_payments']['count'] }} payments overdue</div>
        </td>
        <td width="8"></td>
        <td style="padding:10px; background:#eff6ff; border-radius:8px; text-align:center;">
          <div style="font-size:22px; font-weight:bold; color:#1d4ed8;">{{ $digest['renewals']['count'] }}</div>
          <div style="font-size:11px; color:#6b7280;">Renewals in 30 days</div>
        </td>
        <td width="8"></td>
        <td style="padding:10px; background:#ecfdf5; border-radius:8px; text-align:center;">
          <div style="font-size:22px; font-weight:bold; color:#047857;">₹{{ number_format($digest['collected_last_week']) }}</div>
          <div style="font-size:11px; color:#6b7280;">Collected last week</div>
        </td>
      </tr>
    </table>

    @foreach ([['Overdue deadlines', $digest['overdue_deadlines']['items']], ['Payments overdue', $digest['overdue_payments']['items']], ['Renewals due in the next 30 days', $digest['renewals']['items']]] as [$title, $items])
      @if (count($items))
        <h3 style="font-size:14px; margin:18px 0 6px;">{{ $title }}</h3>
        <table width="100%" cellpadding="6" cellspacing="0" style="font-size:12px; border-collapse:collapse;">
          @foreach ($items as $item)
            <tr style="border-top:1px solid #f1f5f9;">
              <td><a href="{{ $item['url'] }}" style="color:#b45309; text-decoration:none; font-family:monospace;">{{ $item['ref'] }}</a></td>
              <td>{{ $item['what'] }}</td>
              <td style="text-align:right; color:#6b7280; white-space:nowrap;">{{ $item['when'] }}</td>
            </tr>
          @endforeach
        </table>
      @endif
    @endforeach

    <p style="margin-top:24px; font-size:12px;"><a href="{{ $digest['dashboard_url'] }}" style="color:#b45309;">Open the dashboard →</a></p>
    <p style="margin-top:16px; font-size:11px; color:#9ca3af;">Sent every Monday. A super admin can turn this off in Settings → Notifications.</p>
  </div>
</body>
</html>
