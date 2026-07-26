@extends('admin.mfa.layout')

@section('title', 'فعال‌سازی ورود دومرحله‌ای')

@section('content')
    <h1>فعال‌سازی اجباری ورود دومرحله‌ای</h1>
    <p class="hint">برای دسترسی به پنل مدیریت، ابتدا باید ورود دومرحله‌ای (TOTP) را فعال کنید. کد QR زیر را با برنامه Authenticator خود (Google Authenticator، Microsoft Authenticator، Authy یا هر برنامه سازگار با استاندارد) اسکن کنید، یا کلید را به‌صورت دستی وارد کنید.</p>

    <div class="qr">{!! $qrSvg !!}</div>

    <label>کلید راه‌اندازی دستی (فقط همین یک بار نمایش داده می‌شود)</label>
    <div class="manual-key">{{ $manualKey }}</div>

    <form method="POST" action="{{ route('zed-admin.mfa.enroll.confirm') }}">
        @csrf
        <label for="code">کد ۶ رقمی تولیدشده توسط برنامه را وارد کنید</label>
        <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" autofocus required>
        @error('code')
            <div class="error">{{ $message }}</div>
        @enderror
        <button type="submit">تایید و فعال‌سازی</button>
    </form>
@endsection
