@extends('layouts.app')

@section('title', 'تایید آدرس ایمیل')

@section('content')
<div class="min-h-[70vh] flex items-center justify-center px-4 py-10">
    <div class="w-full max-w-md">
        <div class="zed-card p-6 sm:p-8">
            <h1 class="text-xl font-bold text-white mb-2">تایید آدرس ایمیل</h1>
            <p class="text-sm text-gray-400 leading-7 mb-6">
                کد ۶ رقمی ارسال‌شده به
                <span class="font-mono text-gray-200" dir="ltr">{{ $maskedEmail }}</span>
                را وارد کنید. کد تا <span class="font-semibold text-gray-200">{{ $ttlMinutes }} دقیقه</span> معتبر است.
            </p>

            @if(session('success'))
                <div class="mb-4 rounded-xl border border-green-700 bg-green-900/30 px-4 py-3 text-sm text-green-300">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="mb-4 rounded-xl border border-red-700 bg-red-900/30 px-4 py-3 text-sm text-red-300">{{ session('error') }}</div>
            @endif
            @error('code')
                <div class="mb-4 rounded-xl border border-red-700 bg-red-900/30 px-4 py-3 text-sm text-red-300">{{ $message }}</div>
            @enderror

            {{-- Code entry: one accessible six-digit input (paste-friendly,
                 numeric keyboard, one-time-code autofill). --}}
            <form method="POST" action="{{ route('verification.verify') }}" class="mb-5">
                @csrf
                <label for="otp-code" class="sr-only">کد تایید ۶ رقمی</label>
                <input
                    id="otp-code"
                    name="code"
                    type="text"
                    inputmode="numeric"
                    pattern="[0-9]{6}"
                    maxlength="6"
                    autocomplete="one-time-code"
                    autofocus
                    required
                    dir="ltr"
                    placeholder="••••••"
                    class="w-full rounded-xl border border-gray-700 bg-gray-900 px-4 py-3 text-center text-2xl font-mono tracking-[0.6em] text-white focus:border-indigo-500 focus:outline-none"
                    oninput="this.value = this.value.replace(/[^0-9]/g, '').slice(0, 6)">
                <button type="submit"
                    class="mt-4 w-full rounded-xl bg-indigo-600 py-3 font-bold text-white transition hover:bg-indigo-500">
                    تایید ایمیل
                </button>
            </form>

            {{-- Resend: POST only (refreshing the page never re-sends). --}}
            <form method="POST" action="{{ route('verification.resend') }}" class="mb-6">
                @csrf
                <button type="submit" id="resend-btn"
                    class="w-full rounded-xl border border-gray-700 py-2.5 text-sm text-gray-300 transition hover:border-gray-500 disabled:cursor-not-allowed disabled:opacity-50"
                    @if($cooldownSeconds > 0) disabled @endif>
                    <span id="resend-label">ارسال مجدد کد</span>
                </button>
            </form>

            {{-- Mistyped address correction: current password required. --}}
            <details class="mb-4 rounded-xl border border-gray-800 bg-gray-900/50 p-4">
                <summary class="cursor-pointer text-sm text-gray-300">ایمیل را اشتباه وارد کرده‌اید؟ اصلاح آدرس ایمیل</summary>
                <form method="POST" action="{{ route('verification.change') }}" class="mt-4 space-y-3">
                    @csrf
                    @method('PATCH')
                    <div>
                        <label for="new-email" class="mb-1 block text-xs text-gray-400">آدرس ایمیل جدید</label>
                        <input id="new-email" name="email" type="email" required dir="ltr"
                            class="w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2 text-sm text-white focus:border-indigo-500 focus:outline-none"
                            value="{{ old('email') }}">
                        @error('email')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="current-password" class="mb-1 block text-xs text-gray-400">رمز عبور فعلی</label>
                        <input id="current-password" name="password" type="password" required autocomplete="current-password"
                            class="w-full rounded-lg border border-gray-700 bg-gray-900 px-3 py-2 text-sm text-white focus:border-indigo-500 focus:outline-none">
                        @error('password')<p class="mt-1 text-xs text-red-400">{{ $message }}</p>@enderror
                    </div>
                    <button type="submit"
                        class="w-full rounded-lg border border-indigo-700 py-2 text-sm text-indigo-300 transition hover:bg-indigo-900/30">
                        تغییر ایمیل و ارسال کد جدید
                    </button>
                </form>
            </details>

            <form method="POST" action="{{ route('logout') }}" class="text-center">
                @csrf
                <button type="submit" class="text-xs text-gray-500 underline transition hover:text-gray-300">
                    خروج از حساب
                </button>
            </form>
        </div>
    </div>
</div>

<script>
    // Resend countdown (server-side rate limiting is authoritative).
    (function () {
        var remaining = {{ (int) $cooldownSeconds }};
        var btn = document.getElementById('resend-btn');
        var label = document.getElementById('resend-label');
        if (!btn || remaining <= 0) return;
        function tick() {
            if (remaining <= 0) {
                btn.disabled = false;
                label.textContent = 'ارسال مجدد کد';
                return;
            }
            label.textContent = 'ارسال مجدد تا ' + remaining + ' ثانیه دیگر';
            remaining--;
            setTimeout(tick, 1000);
        }
        tick();
    })();
</script>
@endsection
