{{-- Reusable phone + OTP verification section.
     Expects: $user, $verificationEnabled --}}

@error('phone')
<div class="bg-red-500/10 border border-red-500/30 rounded-lg px-4 py-2 text-xs text-red-300">{{ $message }}</div>
@enderror
@error('otp')
<div class="bg-red-500/10 border border-red-500/30 rounded-lg px-4 py-2 text-xs text-red-300">{{ $message }}</div>
@enderror
@error('code')
<div class="bg-red-500/10 border border-red-500/30 rounded-lg px-4 py-2 text-xs text-red-300">{{ $message }}</div>
@enderror

{{-- Current phone --}}
<div class="flex justify-between items-center py-2">
    <span class="text-gray-400">شماره موبایل</span>
    <span class="text-white font-mono" dir="ltr">{{ $user->phone ?? '—' }}</span>
</div>

{{-- Verification status --}}
<div class="flex justify-between items-center py-2 border-b border-gray-800">
    <span class="text-gray-400">وضعیت تایید</span>
    @if($user->hasVerifiedPhone())
        <span class="inline-flex items-center gap-1 text-green-400">✓ تایید شده</span>
    @else
        <span class="inline-flex items-center gap-1 text-amber-400">تایید نشده</span>
    @endif
</div>

{{-- Phone entry / change --}}
<form method="POST" action="{{ route('dashboard.profile.phone.save') }}" class="space-y-2 pt-2">
    @csrf
    <label for="phone" class="block text-xs text-gray-400">{{ $user->hasPhone() ? 'تغییر شماره موبایل' : 'ثبت شماره موبایل' }}</label>
    <div class="flex gap-2">
        <input type="tel" id="phone" name="phone" value="{{ old('phone', $user->phone) }}" dir="ltr" required
               placeholder="مثلاً 09123456789"
               class="flex-1 bg-gray-800 border border-gray-700 focus:border-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm text-left outline-none">
        <button type="submit" class="bg-gray-800 hover:bg-gray-700 text-gray-200 text-sm px-4 py-2.5 rounded-lg transition whitespace-nowrap">
            ذخیره
        </button>
    </div>
</form>

{{-- OTP verification (only when admin enabled verification, phone present, not yet verified) --}}
@if($verificationEnabled && $user->hasPhone() && ! $user->hasVerifiedPhone())
<div class="pt-4 border-t border-gray-800 space-y-3">
    <form method="POST" action="{{ route('dashboard.profile.phone.send-otp') }}">
        @csrf
        <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-4 py-2.5 rounded-lg transition">
            ارسال کد تایید
        </button>
    </form>

    <form method="POST" action="{{ route('dashboard.profile.phone.verify') }}" class="space-y-2">
        @csrf
        <label for="code" class="block text-xs text-gray-400">کد تایید</label>
        <div class="flex gap-2">
            <input type="text" id="code" name="code" inputmode="numeric" maxlength="6" dir="ltr"
                   placeholder="------"
                   class="flex-1 bg-gray-800 border border-gray-700 focus:border-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm text-center tracking-[0.5em] font-mono outline-none">
            <button type="submit" class="bg-green-600 hover:bg-green-500 text-white text-sm font-medium px-4 py-2.5 rounded-lg transition whitespace-nowrap">
                تایید شماره موبایل
            </button>
        </div>
    </form>
</div>
@elseif(! $verificationEnabled)
<p class="text-xs text-gray-600 pt-2">تایید شماره موبایل در حال حاضر غیرفعال است.</p>
@endif
