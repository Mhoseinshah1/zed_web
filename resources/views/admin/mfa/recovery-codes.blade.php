@extends('admin.mfa.layout')

@section('title', 'کدهای بازیابی')

@section('content')
    <h1>کدهای بازیابی یک‌بارمصرف</h1>
    <div class="warn">این کدها فقط همین یک بار نمایش داده می‌شوند و در سرور فقط به‌صورت هش ذخیره شده‌اند. آن‌ها را همین حالا در جای امنی (خارج از این مرورگر) ذخیره کنید. اگر دسترسی به برنامه Authenticator را از دست بدهید، هر کد یک بار امکان ورود می‌دهد — اما تنظیمات حساس را باز نمی‌کند.</div>

    <ul class="codes">
        @foreach ($codes as $code)
            <li>{{ $code }}</li>
        @endforeach
    </ul>

    <form method="POST" action="{{ route('zed-admin.mfa.enroll.acknowledge') }}">
        @csrf
        <button type="submit">کدها را در جای امنی ذخیره کردم — ادامه</button>
    </form>
@endsection
