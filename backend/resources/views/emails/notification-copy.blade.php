<!DOCTYPE html>
<html>
<body style="font-family: Arial, Helvetica, sans-serif; color:#1f2937; background:#f8fafc; margin:0; padding:24px;">
  <div style="max-width:560px; margin:0 auto; background:#ffffff; border:1px solid #e5e7eb; border-radius:10px; padding:22px;">
    <h2 style="margin:0 0 10px; font-size:17px;">{{ $heading }}</h2>
    <p style="margin:0 0 16px; font-size:13px; line-height:1.5;">{{ $body }}</p>
    @if ($url)
      <p style="margin:0;"><a href="{{ $url }}" style="color:#b45309; font-size:13px;">Open in the portal →</a></p>
    @endif
    <p style="margin-top:20px; font-size:11px; color:#9ca3af;">You get this by email because of your choice in Settings → Notifications.</p>
  </div>
</body>
</html>
