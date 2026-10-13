<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $state['title'] }} | MyIPStrategy</title>
    <link rel="icon" type="image/png" href="/favicon.png">
    <style>
        :root {
            --bg: #070b14; --panel: #0e1626; --line: #22314d; --text: #eaf0fa; --muted: #aab6cc;
            --amber: #f5b83d; --steel: #7fa6d9; --ink: #10192b;
            color-scheme: dark;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px;
            background:
                linear-gradient(var(--line) 1px, transparent 1px) 0 0 / 40px 40px,
                linear-gradient(90deg, var(--line) 1px, transparent 1px) 0 0 / 40px 40px,
                radial-gradient(900px 500px at 50% 0%, rgba(245, 184, 61, 0.10), transparent 70%),
                var(--bg);
            color: var(--text);
            font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
        }
        .rig { width: 100%; max-width: 760px; background: var(--panel); border: 2px solid var(--line); border-radius: 14px; box-shadow: 0 30px 80px rgba(0,0,0,.55), inset 0 0 0 6px rgba(255,255,255,.015); overflow: hidden; }
        .hazard { height: 14px; background: repeating-linear-gradient(-45deg, var(--amber) 0 18px, var(--ink) 18px 36px); }
        .bolt-row { display: flex; justify-content: space-between; padding: 10px 14px 0; }
        .bolt { width: 12px; height: 12px; border-radius: 50%; background: radial-gradient(circle at 35% 35%, #51627f, #1a2438); border: 1px solid #0a101c; position: relative; }
        .bolt::after { content: ""; position: absolute; left: 2px; right: 2px; top: 50%; height: 1px; background: #0a101c; transform: rotate(35deg); }
        .body { padding: 18px 32px 32px; text-align: center; }
        .gears { position: relative; width: 190px; height: 130px; margin: 0 auto 8px; }
        .gears svg { position: absolute; fill: none; stroke-linecap: butt; }
        .g1 { left: 0; top: 20px; width: 110px; height: 110px; animation: spin 9s linear infinite; }
        .g2 { left: 85px; top: 0; width: 80px; height: 80px; animation: spin 6s linear infinite reverse; }
        .g3 { left: 120px; top: 62px; width: 62px; height: 62px; animation: spin 4.6s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }
        .status { display: inline-flex; align-items: center; gap: 8px; font: 600 12px/1 ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace; letter-spacing: .14em; text-transform: uppercase; color: var(--amber); border: 1px solid rgba(245,184,61,.5); border-radius: 999px; padding: 8px 14px; }
        .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--amber); animation: blink 1.2s steps(2, jump-none) infinite; }
        @keyframes blink { 50% { opacity: .15; } }
        h1 { margin: 18px 0 6px; font-size: clamp(1.9rem, 6vw, 2.8rem); line-height: 1.1; letter-spacing: -.01em; }
        .tagline { margin: 0 0 18px; font: 600 clamp(1rem, 3vw, 1.2rem)/1.3 ui-monospace, "SFMono-Regular", Menlo, Consolas, monospace; color: var(--steel); }
        .msg { margin: 0 auto 22px; max-width: 54ch; color: var(--muted); white-space: pre-line; }
        .gauge { margin: 0 auto 26px; max-width: 460px; }
        .gauge-track { height: 14px; border: 2px solid var(--line); border-radius: 4px; background: #0a111f; overflow: hidden; }
        .gauge-fill { height: 100%; width: 40%; background: repeating-linear-gradient(90deg, var(--amber) 0 12px, #c78f1d 12px 14px); animation: sweep 2.8s ease-in-out infinite alternate; }
        @keyframes sweep { from { width: 18%; } to { width: 86%; } }
        .gauge-label { display: flex; justify-content: space-between; margin-top: 6px; font: 11px/1 ui-monospace, Menlo, Consolas, monospace; letter-spacing: .12em; text-transform: uppercase; color: var(--muted); }
        .help { border-top: 1px dashed var(--line); padding-top: 20px; color: var(--muted); }
        .help a.mail { color: var(--amber); font-weight: 700; text-underline-offset: 3px; word-break: break-all; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; margin-top: 18px; }
        .btn { min-height: 44px; padding: 10px 20px; border-radius: 10px; font: 700 15px/1 inherit; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; border: 2px solid var(--line); background: transparent; color: var(--text); }
        .btn.primary { background: var(--amber); border-color: var(--amber); color: var(--ink); }
        .btn:focus-visible, a:focus-visible { outline: 3px solid #fff; outline-offset: 3px; }
        .who { margin-top: 14px; font-size: .85rem; color: var(--muted); }
        @media (max-width: 520px) { .body { padding: 14px 18px 24px; } .btn { width: 100%; justify-content: center; } }
        @media (prefers-reduced-motion: reduce) { .g1, .g2, .g3, .gauge-fill, .dot { animation: none; } }
    </style>
</head>
<body>
    <main class="rig" role="main">
        <div class="hazard" aria-hidden="true"></div>
        <div class="bolt-row" aria-hidden="true"><span class="bolt"></span><span class="bolt"></span></div>
        <div class="body">
            <div class="gears" aria-hidden="true">
                <svg class="g1" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" stroke="#f5b83d" stroke-width="14" stroke-dasharray="12.56 12.56"/><circle cx="50" cy="50" r="34" stroke="#f5b83d" stroke-width="6"/><circle cx="50" cy="50" r="16" stroke="#0e1626" stroke-width="12"/><circle cx="50" cy="50" r="7" fill="#0e1626" stroke="#7fa6d9" stroke-width="3"/></svg>
                <svg class="g2" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" stroke="#7fa6d9" stroke-width="14" stroke-dasharray="15.7 15.7"/><circle cx="50" cy="50" r="34" stroke="#7fa6d9" stroke-width="6"/><circle cx="50" cy="50" r="16" stroke="#0e1626" stroke-width="12"/><circle cx="50" cy="50" r="7" fill="#0e1626" stroke="#f5b83d" stroke-width="3"/></svg>
                <svg class="g3" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" stroke="#f5b83d" stroke-width="14" stroke-dasharray="10.5 10.5"/><circle cx="50" cy="50" r="34" stroke="#f5b83d" stroke-width="6"/><circle cx="50" cy="50" r="14" stroke="#0e1626" stroke-width="12"/></svg>
            </div>

            <span class="status"><span class="dot"></span>Scheduled maintenance</span>
            <h1>{{ $state['title'] }}</h1>
            <p class="tagline">{{ $state['tagline'] }}</p>
            <p class="msg">{{ $state['message'] }}</p>

            <div class="gauge" role="img" aria-label="Maintenance in progress">
                <div class="gauge-track"><div class="gauge-fill"></div></div>
                <div class="gauge-label"><span>Calibrating</span><span>In progress</span></div>
            </div>

            <div class="help">
                Need assistance? Contact
                <a class="mail" href="mailto:{{ $state['contact'] }}">{{ $state['contact'] }}</a>
                <div class="actions">
                    <a class="btn primary" href="{{ url()->current() }}">Check again</a>
                    <form method="POST" action="{{ url('/logout') }}">
                        @csrf
                        <button class="btn" type="submit">Sign out</button>
                    </form>
                </div>
                <p class="who">Signed in as {{ $userName }}. Only system administrators can use the portal during maintenance.</p>
            </div>
        </div>
        <div class="bolt-row" aria-hidden="true"><span class="bolt"></span><span class="bolt"></span></div>
        <div class="hazard" aria-hidden="true"></div>
    </main>
</body>
</html>
