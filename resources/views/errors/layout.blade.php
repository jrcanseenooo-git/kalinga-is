<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#311969">
    <title>@yield('title') - Kalinga</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;min-height:100vh;display:grid;place-items:center;padding:16px;background:#f7f6fb;color:#1f1a33;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
        main{width:100%;max-width:30rem;background:#fff;border:1px solid #e4dff0;border-radius:1rem;padding:1.75rem;box-shadow:0 8px 30px rgb(15 23 42/.06)}
        img{height:2.6rem;width:auto}
        .code{margin:1.1rem 0 0;font-size:.75rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#6d28d9}
        h1{margin:.25rem 0 .5rem;font-size:1.4rem;line-height:1.25}
        p{margin:.4rem 0;line-height:1.55;color:#4b4760}
        .actions{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:1.25rem}
        a{display:inline-flex;align-items:center;justify-content:center;min-height:2.75rem;padding:0 1rem;border-radius:.6rem;font-weight:600;text-decoration:none;font-size:.9rem}
        .primary{background:#5b21b6;color:#fff}.secondary{border:1px solid #d6d0e6;color:#4c1d95;background:#fff}
        a:focus-visible{outline:3px solid #a78bfa;outline-offset:2px}
        .ref{margin-top:1rem;font-size:.75rem;color:#6b6780}
    </style>
</head>
<body>
<main aria-labelledby="error-title">
    <img src="/kalinga-logo-purple.png" alt="Kalinga">
    <p class="code">Error @yield('code')</p>
    <h1 id="error-title">@yield('title')</h1>
    @yield('message')
    <div class="actions">
        <a class="primary" href="{{ url('/') }}">@yield('primary', 'Go to Kalinga home')</a>
        @hasSection('secondary')@yield('secondary')@endif
    </div>
    <p class="ref">If this keeps happening, tell your system administrator what you were doing and the time it happened ({{ now('Asia/Manila')->format('M j, Y g:i A') }}).</p>
</main>
</body>
</html>
