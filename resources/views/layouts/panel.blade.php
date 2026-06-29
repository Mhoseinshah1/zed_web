<!DOCTYPE html>
<html lang="fa" dir="rtl" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'داشبورد') | ZedProxy</title>
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
            <a href="{{ route('dashboard.index') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg {{ request()->routeIs('dashboard.index') ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }} transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7h18M3 12h18M3 17h18"/></svg>
                داشبورد
            </a>
            <a href="{{ route('dashboard.services') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg {{ request()->routeIs('dashboard.services') ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }} transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M12 5l7 7-7 7"/></svg>
                سرویس‌های من
            </a>
            <a href="{{ route('dashboard.orders') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg {{ request()->routeIs('dashboard.orders*') ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }} transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/></svg>
                سفارش‌های من
            </a>
            <a href="{{ route('dashboard.wallet') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg {{ request()->routeIs('dashboard.wallet') ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }} transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                کیف پول
            </a>
            @php $unreadNotifications = \App\Models\Notification::forUser(auth()->id())->unread()->count(); @endphp
            <a href="{{ route('dashboard.notifications') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg {{ request()->routeIs('dashboard.notifications') ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }} transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                <span>اعلان‌ها</span>
                @if($unreadNotifications > 0)
                <span class="mr-auto inline-flex items-center justify-center min-w-5 h-5 px-1.5 text-[11px] font-bold rounded-full bg-red-500 text-white">
                    {{ $unreadNotifications > 99 ? '۹۹+' : $unreadNotifications }}
                </span>
                @endif
            </a>
            @php
                $repMode = \App\Services\Referrals\ReferralSettings::mode();
                $showRep = $repMode === \App\Services\Referrals\ReferralSettings::MODE_ALL_USERS
                    || auth()->user()->isApprovedRepresentative()
                    || \App\Services\Referrals\ReferralSettings::representativeSystemEnabled();
            @endphp
            @if($showRep)
            <a href="{{ route('dashboard.representative') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg {{ request()->routeIs('dashboard.representative*') ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }} transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 0a3 3 0 10-2.83-4"/></svg>
                نمایندگی
            </a>
            @endif
            @php $unreadTickets = \App\Models\SupportTicket::forUser(auth()->id())->where('user_unread', true)->count(); @endphp
            <a href="{{ route('dashboard.tickets') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg {{ request()->routeIs('dashboard.tickets*') ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }} transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 13v5a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h5m11-2l-9 9m0 0V4m0 5h5"/></svg>
                <span>تیکت‌های پشتیبانی</span>
                @if($unreadTickets > 0)
                <span class="mr-auto inline-flex items-center justify-center min-w-5 h-5 px-1.5 text-[11px] font-bold rounded-full bg-red-500 text-white">{{ $unreadTickets }}</span>
                @endif
            </a>
            <a href="{{ route('dashboard.profile') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg {{ request()->routeIs('dashboard.profile') ? 'bg-indigo-600 text-white' : 'text-gray-400 hover:text-white hover:bg-gray-800' }} transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                پروفایل
            </a>
        </nav>

        <div class="p-4 border-t border-gray-800">
            <form method="POST" action="{{ route('logout') }}">
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
            @if(session('success'))
                <div class="mb-6 bg-green-500/10 border border-green-500/30 rounded-lg p-4 text-sm text-green-300">
                    {{ session('success') }}
                </div>
            @endif
            @if(session('error'))
                <div class="mb-6 bg-red-500/10 border border-red-500/30 rounded-lg p-4 text-sm text-red-300">
                    {{ session('error') }}
                </div>
            @endif
            @yield('content')
        </main>
    </div>
</div>

@stack('scripts')
</body>
</html>
