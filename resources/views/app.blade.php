<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title inertia>{{ config('app.name', 'Kalinga') }}</title>
        <style nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
            /* Start-up screen while the app loads on a slow connection; hidden as soon as the app renders. */
            .boot{position:fixed;inset:0;display:grid;place-items:center;background:#f7f6fb;font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#4c1d95;z-index:1}
            .boot-box{display:flex;flex-direction:column;align-items:center;gap:.9rem;text-align:center}
            .boot img{width:7rem;height:auto}.boot p{margin:0;font-size:.85rem;color:#5b5670}
            .boot-bar{width:8rem;height:.25rem;overflow:hidden;border-radius:999px;background:#e9e4f7}
            .boot-bar::after{content:"";display:block;width:40%;height:100%;border-radius:999px;background:#7c3aed;animation:boot 1.1s ease-in-out infinite}
            @keyframes boot{0%{transform:translateX(-100%)}100%{transform:translateX(250%)}}
            @media (prefers-reduced-motion:reduce){.boot-bar::after{animation:none;width:100%}}
            #app:not(:empty)~.boot{display:none}
        </style>
        <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=4">
        <meta name="theme-color" content="#311969">

        <!-- Scripts -->
        @routes(null, \Illuminate\Support\Facades\Vite::cspNonce())
        @vite(['resources/js/app.ts', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
        <div class="boot" aria-hidden="true"><div class="boot-box"><img src="/kalinga-logo-purple.png" alt=""><div class="boot-bar"></div><p>Loading Kalinga...</p></div></div>
    </body>
</html>
