@extends('layouts.panel')

@section('title', 'کیف پول')

@section('content')

@if(session('success'))
<div class="mb-5 p-4 rounded-xl bg-green-900/40 border border-green-700 text-green-300 text-sm">
    {{ session('success') }}
</div>
@endif

@if(session('error'))
<div class="mb-5 p-4 rounded-xl bg-red-900/40 border border-red-700 text-red-300 text-sm">
    {{ session('error') }}
</div>
@endif

@if(!$walletEnabled)
{{-- Wallet disabled notice --}}
<div class="bg-yellow-900/30 border border-yellow-700/50 rounded-xl p-6 text-center mb-8">
    <p class="text-yellow-300 text-lg font-semibold">کیف پول در حال حاضر غیرفعال است.</p>
    <p class="text-yellow-500 text-sm mt-2">برای اطلاعات بیشتر با پشتیبانی تماس بگیرید.</p>
    <a href="{{ route('contact') }}" class="inline-block mt-4 text-yellow-400 hover:text-yellow-300 text-sm transition">
        تماس با پشتیبانی ←
    </a>
</div>
@else
{{-- Balance card --}}
<div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-8">
    <div class="md:col-span-1 bg-indigo-600 rounded-xl p-6 flex flex-col justify-between">
        <div>
            <p class="text-indigo-200 text-sm">موجودی کیف پول</p>
            <p class="text-4xl font-bold text-white mt-2">{{ number_format($user->wallet_balance_toman) }}</p>
            <p class="text-indigo-300 text-sm mt-1">تومان</p>
        </div>
        <a href="{{ route('plans') }}" class="mt-6 inline-block bg-white/20 hover:bg-white/30 text-white text-sm font-medium px-4 py-2 rounded-lg transition text-center">
            خرید سرویس VPN
        </a>
    </div>
    <div class="md:col-span-2 bg-gray-900 border border-gray-800 rounded-xl p-6 flex items-center">
        <div class="w-full">
            <h3 class="text-white font-semibold mb-2">شارژ کیف پول</h3>
            @if($topupEnabled)
                <p class="text-gray-400 text-sm leading-6 mb-4">
                    برای شارژ کیف پول خود از طریق درگاه‌های آنلاین اقدام کنید.
                </p>
                <a href="{{ route('dashboard.wallet.topup') }}" class="inline-block bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-5 py-2.5 rounded-lg transition">
                    شارژ کیف پول
                </a>
            @else
                <p class="text-gray-400 text-sm leading-6">
                    شارژ کیف پول در حال حاضر غیرفعال است.<br>
                    برای شارژ با پشتیبانی تماس بگیرید.
                </p>
                <a href="{{ route('contact') }}" class="inline-block mt-4 text-indigo-400 hover:text-indigo-300 text-sm transition">
                    تماس با پشتیبانی ←
                </a>
            @endif
        </div>
    </div>
</div>
@endif

{{-- Transaction history --}}
<div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
    <div class="flex items-center justify-between p-6 border-b border-gray-800">
        <h2 class="font-semibold text-white">تراکنش‌های کیف پول</h2>
    </div>

    @if($transactions->isEmpty())
        <div class="text-center py-12 text-gray-500">
            <div class="text-4xl mb-3">📋</div>
            <p class="text-sm">هیچ تراکنشی ثبت نشده است</p>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-gray-400 text-xs border-b border-gray-800">
                        <th class="text-right px-6 py-3 font-medium">نوع</th>
                        <th class="text-right px-6 py-3 font-medium">مبلغ</th>
                        <th class="text-right px-6 py-3 font-medium">موجودی بعد</th>
                        <th class="text-right px-6 py-3 font-medium">توضیحات</th>
                        <th class="text-right px-6 py-3 font-medium">تاریخ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @foreach($transactions as $tx)
                    <tr class="hover:bg-gray-800/30 transition">
                        <td class="px-6 py-4">
                            <span class="text-white">{{ $tx->typeLabel() }}</span>
                        </td>
                        <td class="px-6 py-4">
                            @if($tx->direction === 'credit')
                                <span class="text-green-400">+{{ number_format($tx->amount_toman) }} تومان</span>
                            @else
                                <span class="text-red-400">-{{ number_format($tx->amount_toman) }} تومان</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-gray-300">
                            {{ number_format($tx->balance_after_toman) }} تومان
                        </td>
                        <td class="px-6 py-4 text-gray-400 max-w-xs truncate">
                            {{ $tx->description ?? '—' }}
                        </td>
                        <td class="px-6 py-4 text-gray-400">
                            {{ $tx->created_at->format('Y/m/d H:i') }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($transactions->hasPages())
        <div class="px-6 py-4 border-t border-gray-800">
            {{ $transactions->links() }}
        </div>
        @endif
    @endif
</div>
@endsection
