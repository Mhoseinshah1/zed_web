@extends('admin.mfa.layout')

@section('title', 'کد تایید دومرحله‌ای')

@section('content')
    <h1>کد تایید دومرحله‌ای</h1>
    <p class="hint">کد ۶ رقمی فعلی را از برنامه Authenticator خود (Google Authenticator، Microsoft Authenticator، Authy یا هر برنامه سازگار) وارد کنید.</p>

    <form method="POST" action="{{ route('zed-admin.mfa.verify') }}">
        @csrf
        <label for="code">کد تایید</label>
        <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" autofocus required>
        @error('code')
            <div class="error">{{ $message }}</div>
        @enderror
        <button type="submit">تایید و ورود</button>
    </form>

    <a class="alt" href="{{ route('zed-admin.mfa.recovery') }}">دسترسی به برنامه Authenticator ندارم — استفاده از کد بازیابی</a>
@endsection
