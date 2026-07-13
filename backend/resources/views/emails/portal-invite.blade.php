<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Your MyIPStrategy Portal Access</title>
  <style>
    body { margin: 0; padding: 0; background: #f4f4f5; font-family: Inter, Arial, sans-serif; color: #18181b; }
    .wrap { max-width: 560px; margin: 40px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
    .header { background: #09090b; padding: 32px 40px 24px; text-align: center; }
    .logo { display: inline-flex; align-items: center; justify-content: center; width: 48px; height: 48px; border-radius: 10px; background: linear-gradient(135deg, #3b82f6, #eab308); color: #fff; font-size: 20px; font-weight: 700; margin-bottom: 16px; }
    .header h1 { margin: 0; color: #ffffff; font-size: 22px; font-weight: 600; }
    .header p { margin: 6px 0 0; color: #a1a1aa; font-size: 13px; }
    .body { padding: 32px 40px; }
    .body p { margin: 0 0 16px; font-size: 15px; line-height: 1.6; color: #3f3f46; }
    .creds { background: #fafafa; border: 1px solid #e4e4e7; border-radius: 8px; padding: 20px 24px; margin: 24px 0; }
    .creds table { width: 100%; border-collapse: collapse; }
    .creds td { padding: 6px 0; font-size: 14px; }
    .creds td:first-child { color: #71717a; width: 110px; }
    .creds td:last-child { font-family: 'Courier New', monospace; font-weight: 600; color: #18181b; }
    .cta { text-align: center; margin: 28px 0 8px; }
    .btn { display: inline-block; background: #eab308; color: #09090b; text-decoration: none; font-weight: 700; font-size: 15px; padding: 13px 32px; border-radius: 8px; }
    .footer { border-top: 1px solid #f4f4f5; padding: 20px 40px; text-align: center; font-size: 12px; color: #a1a1aa; }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="header">
      <div class="logo">IP</div>
      <h1>Welcome to MyIPStrategy</h1>
      <p>Your client portal is ready</p>
    </div>
    <div class="body">
      <p>Hi <strong>{{ $clientName }}</strong>,</p>
      <p>Your client portal account has been set up on <strong>MyIPStrategy</strong>, the IP practice management platform of <strong>MYPL / Metayage</strong>. You can use it to track your matters, view invoices, and access documents.</p>

      <div class="creds">
        <table>
          <tr>
            <td>Email</td>
            <td>{{ $email }}</td>
          </tr>
          <tr>
            <td>Password</td>
            <td>{{ $password }}</td>
          </tr>
        </table>
      </div>

      <div class="cta">
        <a href="{{ $loginUrl }}" class="btn">Sign In to Your Portal</a>
      </div>

      <p style="font-size:13px; color:#71717a; margin-top:24px;">
        We recommend changing your password after your first login. If you did not request this access or have questions, please reply to this email.
      </p>
    </div>
    <div class="footer">
      &copy; {{ date('Y') }} Metayage / MYPL &mdash; MyIPStrategy Platform &mdash; <a href="mailto:mugilvannan@myipstrategy.com" style="color:#a1a1aa;">mugilvannan@myipstrategy.com</a>
    </div>
  </div>
</body>
</html>
