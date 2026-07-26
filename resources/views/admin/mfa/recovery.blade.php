@extends('admin.mfa.layout')

@section('title', 'کد بازیابی')

@section('content')
    <h1>ورود با کد بازیابی</h1>
    <p class="hint">یکی از کدهای بازیابی یک‌بارمصرف خود را وارد کنید. کد بازیابی با کد ۶ رقمی برنامه Authenticator متفاوت است و هر کد فقط یک بار قابل استفاده است.</p>
    <div class="warn">کد بازیابی فقط برای ورود به پنل معتبر است؛ برای باز کردن تنظیمات حساس همچنان کد زنده Authenticator لازم است. پس از ورود، هرچه زودتر Authenticator خود را بازیابی یا جایگزین کنید.</div>

    <form method="POST" action="{{ route('zed-admin.mfa.recovery.verify') }}">
        @csrf
        <label for="recovery_code">کد بازیابی</label>
        <input type="text" id="recovery_code" name="recovery_code" autocomplete="off" maxlength="40" autofocus required>
        @error('recovery_code')
            <div class="error">{{ $message }}</div>
        @enderror
        <button type="submit">ورود</button>
    </form>

    <a class="alt" href="{{ route('zed-admin.mfa.challenge') }}">بازگشت به کد Authenticator</a>
@endsection
