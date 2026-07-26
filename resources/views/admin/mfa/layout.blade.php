<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') — ZedProxy Admin</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: Vazirmatn, Tahoma, sans-serif;
            background: #0f1420; color: #e6e9f2;
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 1.5rem;
        }
        .card {
            background: #161d2e; border: 1px solid #232c42; border-radius: 14px;
            padding: 2rem; width: 100%; max-width: 30rem;
            box-shadow: 0 10px 40px -18px rgb(0 0 0 / .6);
        }
        h1 { font-size: 1.15rem; margin-bottom: .5rem; }
        p.hint { color: #9aa3ba; font-size: .85rem; line-height: 1.9; margin-bottom: 1.2rem; }
        label { display: block; font-size: .85rem; margin-bottom: .4rem; }
        input[type=text] {
            width: 100%; padding: .65rem .8rem; border-radius: 9px;
            border: 1px solid #2c3752; background: #0f1420; color: #e6e9f2;
            font-size: 1.05rem; letter-spacing: .2em; text-align: center; direction: ltr;
        }
        button {
            width: 100%; margin-top: 1rem; padding: .7rem; border: 0; border-radius: 9px;
            background: #6366f1; color: #fff; font-size: .95rem; font-family: inherit; cursor: pointer;
        }
        button:hover { background: #575ae8; }
        .error { color: #f87171; font-size: .82rem; margin-top: .6rem; }
        .warn {
            background: #3b2f14; border: 1px solid #6b5620; color: #fbbf24;
            border-radius: 9px; padding: .7rem .9rem; font-size: .82rem; line-height: 1.9; margin-bottom: 1rem;
        }
        a.alt { display: block; margin-top: 1.1rem; color: #93c5fd; font-size: .82rem; text-align: center; text-decoration: none; }
        .qr { background: #fff; border-radius: 10px; padding: .8rem; width: fit-content; margin: 0 auto 1rem; }
        .manual-key {
            direction: ltr; text-align: center; font-family: monospace; font-size: .95rem;
            background: #0f1420; border: 1px dashed #2c3752; border-radius: 9px;
            padding: .6rem; margin-bottom: 1rem; word-break: break-all; user-select: all;
        }
        ul.codes { list-style: none; display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; margin: 1rem 0; }
        ul.codes li {
            direction: ltr; text-align: center; font-family: monospace; font-size: .85rem;
            background: #0f1420; border: 1px solid #2c3752; border-radius: 8px; padding: .5rem; user-select: all;
        }
    </style>
</head>
<body>
    <div class="card">
        @yield('content')
    </div>
</body>
</html>
