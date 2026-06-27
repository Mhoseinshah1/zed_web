@extends('layouts.panel')

@section('title', 'سفارش‌های من')

@section('content')
<div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
    <div class="flex items-center justify-between p-6 border-b border-gray-800">
        <h2 class="font-semibold text-white">سفارش‌های من</h2>
        <a href="{{ route('plans') }}" class="bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-4 py-2 rounded-lg transition">
            خرید جدید
        </a>
    </div>

    @if($orders->isEmpty())
        <div class="text-center py-16 text-gray-500">
            <div class="text-5xl mb-4">🛒</div>
            <p class="text-sm">هنوز سفارشی ثبت نشده است</p>
            <a href="{{ route('plans') }}" class="inline-block mt-5 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-6 py-2.5 rounded-lg transition">
                مشاهده پلن‌ها
            </a>
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-gray-400 text-xs border-b border-gray-800">
                        <th class="text-right px-6 py-3 font-medium">شماره سفارش</th>
                        <th class="text-right px-6 py-3 font-medium">پلن</th>
                        <th class="text-right px-6 py-3 font-medium">مبلغ</th>
                        <th class="text-right px-6 py-3 font-medium">وضعیت سفارش</th>
                        <th class="text-right px-6 py-3 font-medium">وضعیت پرداخت</th>
                        <th class="text-right px-6 py-3 font-medium">تاریخ ثبت</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @foreach($orders as $order)
                    <tr class="hover:bg-gray-800/40 transition">
                        <td class="px-6 py-4 font-mono text-xs text-gray-300">{{ $order->order_number }}</td>
                        <td class="px-6 py-4 text-white">{{ $order->plan_name }}</td>
                        <td class="px-6 py-4 text-white">{{ number_format($order->final_price_toman) }} تومان</td>
                        <td class="px-6 py-4">
                            @php
                                $statusColor = match($order->status) {
                                    'completed'        => 'text-green-400',
                                    'cancelled','failed' => 'text-red-400',
                                    'paid','processing'  => 'text-blue-400',
                                    default              => 'text-yellow-400',
                                };
                            @endphp
                            <span class="{{ $statusColor }}">{{ $order->statusLabel() }}</span>
                        </td>
                        <td class="px-6 py-4">
                            @php
                                $payColor = match($order->payment_status) {
                                    'paid'    => 'text-green-400',
                                    'failed'  => 'text-red-400',
                                    default   => 'text-gray-400',
                                };
                            @endphp
                            <span class="{{ $payColor }}">{{ $order->paymentStatusLabel() }}</span>
                        </td>
                        <td class="px-6 py-4 text-gray-400">{{ $order->created_at->format('Y/m/d') }}</td>
                        <td class="px-6 py-4 text-left">
                            <a href="{{ route('dashboard.orders.show', $order) }}"
                               class="text-indigo-400 hover:text-indigo-300 text-xs font-medium">
                                جزئیات
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($orders->hasPages())
        <div class="px-6 py-4 border-t border-gray-800">
            {{ $orders->links() }}
        </div>
        @endif
    @endif
</div>
@endsection
