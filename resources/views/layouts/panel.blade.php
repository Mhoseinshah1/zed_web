<!DOCTYPE html>
<html lang="fa" dir="rtl" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'پنل کاربری') | ZedProxy</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>body { font-family: 'Vazirmatn', system-ui, sans-serif; }</style>
</head>
<body class="bg-gray-950 text-gray-100 antialiased">

<div class="flex min-h-screen">
    <!-- Sidebar -->
    <aside class="w-64 bg-gray-900 border-l border-gray-800 flex flex-col shrink-0">
        <div class="p-6 border-b border-gray-800">
            <a href="{{ route('home') }}" class="text-lg font-bold text-white">
                <span class="text-indigo-400">Zed</span>Proxy
            </a>
            <p class="text-xs text-gray-500 mt-1">پنل کاربری</p>
        </div>

        <nav class="flex-1 p-4 space-y-1 text-sm">
            <a href="{{ route('panel.dashboard') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg {{ request()->routeIs('panel.dashboard') ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }} transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7h18M3 12h18M3 17h18"/></svg>
                داشبورد
            </a>
            <a href="{{ route('panel.services') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg {{ request()->routeIs('panel.services') ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }} transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M12 5l7 7-7 7"/></svg>
                سرویس‌های من
            </a>
            <a href="{{ route('panel.orders') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg {{ request()->routeIs('panel.orders') ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }} transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/></svg>
                سفارش‌ها
            </a>
            <a href="{{ route('panel.tickets') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg {{ request()->routeIs('panel.tickets') ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }} transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                تیکت‌ها
            </a>
            <a href="{{ route('panel.profile') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg {{ request()->routeIs('panel.profile') ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }} transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                پروفایل
            </a>
        </nav>

        <div class="p-4 border-t border-gray-800">
            <form method="POST" action="{{ route('panel.logout') }}">
                @csrf
                <button type="submit" class="w-full flex items-center gap-3 px-3 py-2 text-sm text-red-400 hover:text-red-300 hover:bg-gray-800 rounded-lg transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                    خروج
                </button>
            </form>
        </div>
    </aside>

    <!-- Main content -->
    <div class="flex-1 flex flex-col min-w-0">
        <header class="bg-gray-900 border-b border-gray-800 px-6 py-4 flex items-center justify-between">
            <h1 class="text-lg font-semibold text-white">@yield('title', 'داشبورد')</h1>
            <div class="text-sm text-gray-400">
                خوش آمدید، <span class="text-white font-medium">{{ auth()->user()->name ?? 'کاربر' }}</span>
            </div>
        </header>
        <main class="flex-1 p-6">
            @yield('content')
        </main>
    </div>
</div>

@stack('scripts')
</body>
</html>
