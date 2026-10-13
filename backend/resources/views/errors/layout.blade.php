<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') | MyIPStrategy</title>
    <link rel="icon" type="image/png" href="/favicon.png">
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; background: #09090b; color: #f4f4f5; font: 16px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; text-align: center; }
        .code { margin: 0; font-size: clamp(4rem, 16vw, 7rem); font-weight: 800; color: #f5b83d; line-height: 1; }
        h1 { margin: 12px 0 8px; font-size: 1.6rem; }
        p { margin: 0 0 24px; color: #c4c4cc; }
        a { display: inline-block; min-height: 44px; padding: 10px 20px; border-radius: 10px; background: #f5b83d; color: #10192b; font-weight: 700; text-decoration: none; }
        a:focus-visible { outline: 3px solid #fff; outline-offset: 3px; }
    </style>
</head>
<body>
    <main>
        <p class="code" aria-hidden="true">@yield('code')</p>
        <h1>@yield('heading')</h1>
        <p>@yield('message')</p>
        <a href="{{ url('/') }}">Back to dashboard</a>
    </main>
</body>
</html>
