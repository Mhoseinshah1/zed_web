<x-filament-panels::page>

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js" defer></script>
    @endpush

    {{-- ─── Date Filter ────────────────────────────────────────────────── --}}
    <div class="mb-6 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="mb-3 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">فیلتر بازه زمانی</h3>
            <div class="flex gap-2">
                <button wire:click="resetToToday"
                    class="rounded-lg border border-gray-200 px-3 py-1 text-xs text-gray-600 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-400 dark:hover:bg-gray-700">
                    امروز
                </button>
                <button wire:click="resetToMonth"
                    class="rounded-lg border border-gray-200 px-3 py-1 text-xs text-gray-600 transition hover:bg-gray-50 dark:border-gray-600 dark:text-gray-400 dark:hover:bg-gray-700">
                    این ماه
                </button>
            </div>
        </div>
        <div class="flex flex-wrap items-end gap-4">
            {{ $this->form }}
            <button wire:click="applyFilter"
                class="rounded-lg bg-primary-600 px-5 py-2 text-sm font-medium text-white shadow transition hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500">
                <span wire:loading.remove wire:target="applyFilter">اعمال فیلتر</span>
                <span wire:loading wire:target="applyFilter">در حال بارگذاری…</span>
            </button>
        </div>
        <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
            بازه انتخاب شده: {{ \Carbon\Carbon::parse($dateFrom)->format('Y-m-d') }}
            تا {{ \Carbon\Carbon::parse($dateTo)->format('Y-m-d') }}
        </p>
    </div>

    {{-- ─── Today KPI Cards ─────────────────────────────────────────────── --}}
    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
        آمار امروز
    </div>
    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
        @php
            $todayCards = [
                ['label'=>'فروش امروز','value'=>number_format($this->getSalesToday()).' تومان','color'=>'green','icon'=>'💰','desc'=>'درآمد از سفارش‌های پرداخت‌شده'],
                ['label'=>'فروش این ماه','value'=>number_format($this->getSalesMonth()).' تومان','color'=>'green','icon'=>'📈','desc'=>'جمع فروش از ابتدای ماه'],
                ['label'=>'سفارش‌های پرداخت‌شده امروز','value'=>number_format($this->getPaidOrdersToday()),'color'=>'blue','icon'=>'🛒','desc'=>'تعداد'],
                ['label'=>'پرداخت‌های در انتظار','value'=>number_format($this->getPendingPaymentsCount()),'color'=>'yellow','icon'=>'⏳','desc'=>'نیاز به بررسی'],
                ['label'=>'خطاهای ساخت سرویس','value'=>number_format($this->getProvisioningFailedCount()),'color'=>'red','icon'=>'🚨','desc'=>'نیاز به اقدام فوری'],
            ];
            $colorMap = ['green'=>'border-l-green-500 bg-green-50 dark:bg-green-900/10','blue'=>'border-l-blue-500 bg-blue-50 dark:bg-blue-900/10','yellow'=>'border-l-yellow-500 bg-yellow-50 dark:bg-yellow-900/10','red'=>'border-l-red-500 bg-red-50 dark:bg-red-900/10'];
            $textMap = ['green'=>'text-green-700 dark:text-green-400','blue'=>'text-blue-700 dark:text-blue-400','yellow'=>'text-yellow-700 dark:text-yellow-400','red'=>'text-red-700 dark:text-red-400'];
        @endphp
        @foreach ($todayCards as $card)
            <div class="rounded-xl border-l-4 border border-gray-200 p-4 shadow-sm {{ $colorMap[$card['color']] }} dark:border-gray-700">
                <div class="flex items-center gap-2 mb-1">
                    <span class="text-lg">{{ $card['icon'] }}</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $card['label'] }}</span>
                </div>
                <div class="text-xl font-bold {{ $textMap[$card['color']] }} leading-tight">{{ $card['value'] }}</div>
                <div class="mt-1 text-xs text-gray-400 dark:text-gray-500">{{ $card['desc'] }}</div>
            </div>
        @endforeach
    </div>

    {{-- ─── Provider Sales Cards (Today) ───────────────────────────────── --}}
    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
        فروش بر اساس روش پرداخت (امروز)
    </div>
    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @php
            $providerCards = [
                ['label'=>'فروش با کیف پول','value'=>number_format($this->getWalletSalesToday()).' تومان','icon'=>'👛','color'=>'blue'],
                ['label'=>'فروش با NOWPayments','value'=>number_format($this->getNowPaymentsSalesToday()).' تومان','icon'=>'₿','color'=>'yellow'],
                ['label'=>'فروش با CentralPay','value'=>number_format($this->getCentralPaySalesToday()).' تومان','icon'=>'🏦','color'=>'green'],
                ['label'=>'شارژ کیف پول امروز','value'=>number_format($this->getWalletTopupToday()).' تومان','icon'=>'💳','color'=>'blue','note'=>'واریز - نه فروش'],
            ];
        @endphp
        @foreach ($providerCards as $card)
            <div class="rounded-xl border border-gray-200 p-4 shadow-sm {{ $colorMap[$card['color']] }} dark:border-gray-700">
                <div class="flex items-center gap-2 mb-1">
                    <span class="text-lg">{{ $card['icon'] }}</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $card['label'] }}</span>
                </div>
                <div class="text-lg font-bold {{ $textMap[$card['color']] }}">{{ $card['value'] }}</div>
                @if(!empty($card['note']))
                    <span class="mt-1 inline-block rounded-full bg-blue-100 px-2 py-0.5 text-xs text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">
                        {{ $card['note'] }}
                    </span>
                @endif
            </div>
        @endforeach
    </div>

    {{-- ─── Range KPI Cards ─────────────────────────────────────────────── --}}
    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
        آمار بازه انتخاب‌شده
    </div>
    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        @php
            $rangeCards = [
                ['label'=>'فروش ناخالص (قبل از تخفیف)','value'=>number_format($this->getGrossSalesRange()).' تومان','color'=>'blue','icon'=>'🧾'],
                ['label'=>'مجموع تخفیف‌ها در بازه','value'=>number_format($this->getOrderDiscountsRange()).' تومان','color'=>'yellow','icon'=>'🏷'],
                ['label'=>'فروش خالص (بعد از تخفیف)','value'=>number_format($this->getNetSalesRange()).' تومان','color'=>'green','icon'=>'💵'],
                ['label'=>'پورسانت نمایندگان','value'=>number_format($this->getCommissionsRange()).' تومان','color'=>'yellow','icon'=>'🤝'],
                ['label'=>'خالص پس از پورسانت','value'=>number_format($this->getNetAfterCommissionsRange()).' تومان','color'=>'green','icon'=>'📊'],
                ['label'=>'سفارش‌های پرداخت‌شده','value'=>number_format($this->getPaidOrdersRange()),'color'=>'blue','icon'=>'🛒'],
                ['label'=>'درآمد خرید سرویس جدید','value'=>number_format($this->getNewServiceSalesRange()).' تومان','color'=>'green','icon'=>'🆕'],
                ['label'=>'خریدهای جدید','value'=>number_format($this->getNewServiceOrdersRange()),'color'=>'blue','icon'=>'🛒'],
                ['label'=>'درآمد تمدید سرویس','value'=>number_format($this->getRenewalSalesRange()).' تومان','color'=>'green','icon'=>'🔄'],
                ['label'=>'تمدیدهای موفق','value'=>number_format($this->getRenewalOrdersRange()),'color'=>'blue','icon'=>'↻'],
                ['label'=>'کش‌بک تمدید در بازه','value'=>number_format($this->getRenewalCashbackRange()).' تومان','color'=>'yellow','icon'=>'💸'],
                ['label'=>'درآمد خرید حجم اضافه','value'=>number_format($this->getExtraTrafficSalesRange()).' تومان','color'=>'green','icon'=>'📦'],
                ['label'=>'تعداد خرید حجم اضافه','value'=>number_format($this->getExtraTrafficOrdersRange()),'color'=>'blue','icon'=>'📦'],
                ['label'=>'درآمد خرید زمان اضافه','value'=>number_format($this->getExtraTimeSalesRange()).' تومان','color'=>'green','icon'=>'⏱'],
                ['label'=>'تعداد خرید زمان اضافه','value'=>number_format($this->getExtraTimeOrdersRange()),'color'=>'blue','icon'=>'⏱'],
                ['label'=>'شارژ کیف پول در بازه','value'=>number_format($this->getWalletTopupRange()).' تومان','color'=>'blue','icon'=>'💳'],
                ['label'=>'پرداخت‌های ناموفق در بازه','value'=>number_format($this->getFailedPaymentsCount()),'color'=>'red','icon'=>'❌'],
            ];
        @endphp
        @foreach ($rangeCards as $card)
            <div class="rounded-xl border border-gray-200 p-4 shadow-sm {{ $colorMap[$card['color']] }} dark:border-gray-700">
                <div class="flex items-center gap-2 mb-1">
                    <span class="text-lg">{{ $card['icon'] }}</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $card['label'] }}</span>
                </div>
                <div class="text-xl font-bold {{ $textMap[$card['color']] }}">{{ $card['value'] }}</div>
            </div>
        @endforeach
    </div>

    {{-- ─── Discount Summary ────────────────────────────────────────────── --}}
    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
        مجموع تخفیف‌های اعمال‌شده
    </div>
    <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3">
        @php
            $discountCards = [
                ['label'=>'تخفیف امروز','value'=>number_format($this->getTotalDiscountsToday()).' تومان','color'=>'yellow','icon'=>'🏷️'],
                ['label'=>'تخفیف در بازه','value'=>number_format($this->getTotalDiscountsRange()).' تومان','color'=>'yellow','icon'=>'🎟️'],
                ['label'=>'تعداد استفاده در بازه','value'=>number_format($this->getDiscountCountRange()),'color'=>'blue','icon'=>'🔢'],
            ];
        @endphp
        @foreach ($discountCards as $card)
            <div class="rounded-xl border border-gray-200 p-4 shadow-sm {{ $colorMap[$card['color']] }} dark:border-gray-700">
                <div class="flex items-center gap-2 mb-1">
                    <span class="text-lg">{{ $card['icon'] }}</span>
                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $card['label'] }}</span>
                </div>
                <div class="text-xl font-bold {{ $textMap[$card['color']] }}">{{ $card['value'] }}</div>
            </div>
        @endforeach
    </div>

    {{-- ─── Charts ──────────────────────────────────────────────────────── --}}
    <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
        نمودارها
    </div>
    <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">

        {{-- 7-day Sales --}}
        @php $chart7 = $this->getSales7DaysChartData(); @endphp
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h3 class="mb-4 text-sm font-semibold text-gray-700 dark:text-gray-300">نمودار فروش ۷ روز اخیر</h3>
            <div class="relative h-48" x-data x-init="
                (() => {
                    const ctx = $el.querySelector('canvas').getContext('2d');
                    const init = () => {
                        new Chart(ctx, {
                            type: 'bar',
                            data: {
                                labels: {{ json_encode($chart7['labels']) }},
                                datasets: [{
                                    label: 'فروش (تومان)',
                                    data: {{ json_encode($chart7['data']) }},
                                    backgroundColor: 'rgba(99,102,241,0.7)',
                                    borderRadius: 6,
                                }]
                            },
                            options: {
                                responsive: true, maintainAspectRatio: false,
                                plugins: { legend: { display: false } },
                                scales: { y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString() } } }
                            }
                        });
                    };
                    if (window.Chart) init();
                    else { const s = document.querySelector('[src*=chart]'); s && s.addEventListener('load', init); }
                })()
            ">
                <canvas></canvas>
            </div>
        </div>

        {{-- 30-day Sales --}}
        @php $chart30 = $this->getSales30DaysChartData(); @endphp
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h3 class="mb-4 text-sm font-semibold text-gray-700 dark:text-gray-300">روند فروش ۳۰ روز اخیر</h3>
            <div class="relative h-48" x-data x-init="
                (() => {
                    const ctx = $el.querySelector('canvas').getContext('2d');
                    const init = () => {
                        new Chart(ctx, {
                            type: 'line',
                            data: {
                                labels: {{ json_encode($chart30['labels']) }},
                                datasets: [{
                                    label: 'فروش (تومان)',
                                    data: {{ json_encode($chart30['data']) }},
                                    borderColor: 'rgb(34,197,94)',
                                    backgroundColor: 'rgba(34,197,94,0.1)',
                                    tension: 0.4, fill: true, pointRadius: 2,
                                }]
                            },
                            options: {
                                responsive: true, maintainAspectRatio: false,
                                plugins: { legend: { display: false } },
                                scales: { y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString() } } }
                            }
                        });
                    };
                    if (window.Chart) init();
                    else { document.addEventListener('DOMContentLoaded', () => setTimeout(init, 500)); }
                })()
            ">
                <canvas></canvas>
            </div>
        </div>

        {{-- Provider Chart --}}
        @php $provChart = $this->getPaymentProviderChartData(); @endphp
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h3 class="mb-4 text-sm font-semibold text-gray-700 dark:text-gray-300">تفکیک فروش بر اساس روش پرداخت</h3>
            <div class="relative h-48" x-data x-init="
                (() => {
                    const ctx = $el.querySelector('canvas').getContext('2d');
                    const init = () => {
                        new Chart(ctx, {
                            type: 'doughnut',
                            data: {
                                labels: {{ json_encode($provChart['labels']) }},
                                datasets: [{
                                    data: {{ json_encode($provChart['data']) }},
                                    backgroundColor: ['#3b82f6','#f59e0b','#22c55e','#6b7280'],
                                    hoverOffset: 4
                                }]
                            },
                            options: {
                                responsive: true, maintainAspectRatio: false,
                                plugins: { legend: { position: 'right', labels: { font: { size: 11 } } } }
                            }
                        });
                    };
                    if (window.Chart) init();
                    else { document.addEventListener('DOMContentLoaded', () => setTimeout(init, 500)); }
                })()
            ">
                <canvas></canvas>
            </div>
        </div>

        {{-- Wallet Topup Chart --}}
        @php $walletChart = $this->getWalletTopup30DaysChartData(); @endphp
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <h3 class="mb-4 text-sm font-semibold text-gray-700 dark:text-gray-300">شارژ کیف پول ۳۰ روز اخیر</h3>
            <div class="relative h-48" x-data x-init="
                (() => {
                    const ctx = $el.querySelector('canvas').getContext('2d');
                    const init = () => {
                        new Chart(ctx, {
                            type: 'bar',
                            data: {
                                labels: {{ json_encode($walletChart['labels']) }},
                                datasets: [{
                                    label: 'شارژ کیف پول (تومان)',
                                    data: {{ json_encode($walletChart['data']) }},
                                    backgroundColor: 'rgba(59,130,246,0.65)',
                                    borderRadius: 5,
                                }]
                            },
                            options: {
                                responsive: true, maintainAspectRatio: false,
                                plugins: { legend: { display: false } },
                                scales: { y: { beginAtZero: true, ticks: { callback: v => v.toLocaleString() } } }
                            }
                        });
                    };
                    if (window.Chart) init();
                    else { document.addEventListener('DOMContentLoaded', () => setTimeout(init, 500)); }
                })()
            ">
                <canvas></canvas>
            </div>
        </div>

    </div>

    {{-- Payment Status Chart --}}
    @php $statusChart = $this->getPaymentStatusChartData(); @endphp
    <div class="mb-6 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h3 class="mb-4 text-sm font-semibold text-gray-700 dark:text-gray-300">وضعیت پرداخت‌ها در بازه انتخاب‌شده</h3>
        <div class="relative h-48" x-data x-init="
            (() => {
                const ctx = $el.querySelector('canvas').getContext('2d');
                const init = () => {
                    new Chart(ctx, {
                        type: 'bar',
                        data: {
                            labels: {{ json_encode($statusChart['labels']) }},
                            datasets: [{
                                label: 'تعداد',
                                data: {{ json_encode($statusChart['data']) }},
                                backgroundColor: ['#22c55e','#f59e0b','#ef4444','#6b7280','#8b5cf6'],
                                borderRadius: 6,
                            }]
                        },
                        options: {
                            responsive: true, maintainAspectRatio: false,
                            plugins: { legend: { display: false } },
                            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
                        }
                    });
                };
                if (window.Chart) init();
                else { document.addEventListener('DOMContentLoaded', () => setTimeout(init, 500)); }
            })()
        ">
            <canvas></canvas>
        </div>
    </div>

    {{-- ─── Provider Breakdown Table ────────────────────────────────────── --}}
    <div class="mb-6 rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">تفکیک روش‌های پرداخت</h3>
            <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">شارژ کیف پول در این جدول نیست — فقط فروش واقعی</p>
        </div>
        <div class="overflow-x-auto zp-scroll-box">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-100 bg-gray-50 text-right dark:border-gray-700 dark:bg-gray-900/30">
                        <th class="px-5 py-3 text-xs font-medium text-gray-500">روش پرداخت</th>
                        <th class="px-5 py-3 text-xs font-medium text-gray-500">تعداد موفق</th>
                        <th class="px-5 py-3 text-xs font-medium text-gray-500">مبلغ فروش</th>
                        <th class="px-5 py-3 text-xs font-medium text-gray-500">سهم از فروش</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach ($this->getProviderBreakdown() as $row)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                            <td class="px-5 py-3 font-medium text-gray-800 dark:text-gray-200">{{ $row['label'] }}</td>
                            <td class="px-5 py-3 text-gray-600 dark:text-gray-400">{{ number_format($row['count']) }}</td>
                            <td class="px-5 py-3 font-semibold text-gray-800 dark:text-gray-200">{{ number_format($row['total']) }} تومان</td>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-2">
                                    <div class="h-2 flex-1 max-w-24 rounded-full bg-gray-200 dark:bg-gray-700">
                                        <div class="h-2 rounded-full bg-primary-500" style="width: {{ min($row['share'],100) }}%"></div>
                                    </div>
                                    <span class="text-xs text-gray-500">{{ $row['share'] }}%</span>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- ─── Wallet Summary ──────────────────────────────────────────────── --}}
    @php $wallet = $this->getWalletSummary(); @endphp
    <div class="mb-6 rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <div class="border-b border-gray-200 px-5 py-4 dark:border-gray-700">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300">گزارش کیف پول</h3>
        </div>
        <div class="grid grid-cols-2 divide-x divide-x-reverse divide-gray-100 sm:grid-cols-4 dark:divide-gray-700">
            @php
                $walletItems = [
                    ['label'=>'موجودی کل کیف پول کاربران','value'=>number_format($wallet['total_balance']).' تومان','icon'=>'👛','color'=>'blue'],
                    ['label'=>'شارژ کیف پول در بازه','value'=>number_format($wallet['topup_range']).' تومان','icon'=>'➕','color'=>'green'],
                    ['label'=>'خرج‌شده برای خرید سرویس (بازه)','value'=>number_format($wallet['spending_range']).' تومان','icon'=>'➖','color'=>'red'],
                    ['label'=>'تعداد تراکنش‌های ادمین','value'=>number_format($wallet['admin_count']),'icon'=>'🔧','color'=>'yellow'],
                ];
            @endphp
            @foreach ($walletItems as $item)
                <div class="p-4 text-center">
                    <div class="text-lg mb-1">{{ $item['icon'] }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ $item['label'] }}</div>
                    <div class="font-bold text-gray-800 dark:text-gray-200 text-sm">{{ $item['value'] }}</div>
                </div>
            @endforeach
        </div>
        <div class="border-t border-gray-100 px-5 py-3 dark:border-gray-700">
            <div class="grid grid-cols-3 gap-4 text-center text-xs text-gray-500 dark:text-gray-400">
                <div>شارژ کیف پول این ماه: <strong class="text-blue-600">{{ number_format($wallet['topup_month']) }} تومان</strong></div>
                <div>خرج‌شده این ماه: <strong class="text-red-500">{{ number_format($wallet['spending_month']) }} تومان</strong></div>
                <div>تعداد شارژهای موفق در بازه: <strong>{{ number_format($wallet['topup_count']) }}</strong></div>
            </div>
        </div>
    </div>

    {{-- ─── Risky Items ─────────────────────────────────────────────────── --}}
    <div class="mb-2 flex items-center gap-2">
        <span class="text-lg">🚨</span>
        <span class="text-sm font-semibold text-red-600 dark:text-red-400">نیازمند بررسی</span>
    </div>

    @php
        $provFailed   = $this->getProvisioningFailedOrders();
        $oldPending   = $this->getOldPendingPayments();
        $recentFailed = $this->getRecentFailedPayments();
        $paidNoSvc    = $this->getPaidWithoutService();
    @endphp

    <div class="mb-6 grid grid-cols-1 gap-4 lg:grid-cols-2">

        {{-- Provisioning Failed --}}
        <div class="rounded-xl border border-red-200 bg-red-50 shadow-sm dark:border-red-800/50 dark:bg-red-900/10">
            <div class="border-b border-red-200 px-5 py-3 dark:border-red-800/50">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-semibold text-red-700 dark:text-red-400">
                        سفارش‌های دارای خطای ساخت سرویس
                    </span>
                    <a href="{{ route('filament.zed-admin.resources.orders.index', ['tableFilters[provisioning_failed][isActive]=1']) }}"
                       class="text-xs text-red-600 underline dark:text-red-400">مشاهده همه</a>
                </div>
            </div>
            <div class="p-4 zp-scroll-box">
                @forelse ($provFailed as $order)
                    <div class="mb-2 flex items-center justify-between rounded-lg bg-white px-3 py-2 text-xs dark:bg-gray-800">
                        <div>
                            <span class="font-mono font-medium">{{ $order->order_number }}</span>
                            <span class="mx-1 text-gray-400">—</span>
                            <span class="text-gray-600 dark:text-gray-400">{{ $order->user?->username ?? '—' }}</span>
                        </div>
                        <a href="{{ route('filament.zed-admin.resources.orders.edit', $order) }}"
                           class="rounded px-2 py-0.5 bg-red-100 text-red-700 hover:bg-red-200 dark:bg-red-900/30 dark:text-red-400">
                            ویرایش
                        </a>
                    </div>
                @empty
                    <p class="text-center text-sm text-green-600 dark:text-green-400 py-2">✅ هیچ خطایی وجود ندارد</p>
                @endforelse
            </div>
        </div>

        {{-- Paid without service --}}
        <div class="rounded-xl border border-orange-200 bg-orange-50 shadow-sm dark:border-orange-800/50 dark:bg-orange-900/10">
            <div class="border-b border-orange-200 px-5 py-3 dark:border-orange-800/50">
                <span class="text-sm font-semibold text-orange-700 dark:text-orange-400">
                    پرداخت‌های موفق بدون سرویس فعال
                </span>
            </div>
            <div class="p-4 zp-scroll-box">
                @forelse ($paidNoSvc as $order)
                    <div class="mb-2 flex items-center justify-between rounded-lg bg-white px-3 py-2 text-xs dark:bg-gray-800">
                        <div>
                            <span class="font-mono font-medium">{{ $order->order_number }}</span>
                            <span class="mx-1 text-gray-400">—</span>
                            <span class="text-gray-600 dark:text-gray-400">{{ $order->user?->username ?? '—' }}</span>
                        </div>
                        <a href="{{ route('filament.zed-admin.resources.orders.edit', $order) }}"
                           class="rounded px-2 py-0.5 bg-orange-100 text-orange-700 hover:bg-orange-200 dark:bg-orange-900/30 dark:text-orange-400">
                            بررسی
                        </a>
                    </div>
                @empty
                    <p class="text-center text-sm text-green-600 dark:text-green-400 py-2">✅ همه پرداخت‌ها سرویس دارند</p>
                @endforelse
            </div>
        </div>

        {{-- Old pending payments --}}
        <div class="rounded-xl border border-yellow-200 bg-yellow-50 shadow-sm dark:border-yellow-800/50 dark:bg-yellow-900/10">
            <div class="border-b border-yellow-200 px-5 py-3 dark:border-yellow-800/50">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-semibold text-yellow-700 dark:text-yellow-400">
                        پرداخت‌های در انتظار قدیمی (بیش از ۱۲ ساعت)
                    </span>
                    <a href="{{ route('filament.zed-admin.resources.payment-transactions.index') }}"
                       class="text-xs text-yellow-600 underline dark:text-yellow-400">مشاهده همه</a>
                </div>
            </div>
            <div class="p-4 zp-scroll-box">
                @forelse ($oldPending as $tx)
                    <div class="mb-2 flex items-center justify-between rounded-lg bg-white px-3 py-2 text-xs dark:bg-gray-800">
                        <div>
                            <span class="font-mono text-gray-700 dark:text-gray-300">#{{ $tx->id }}</span>
                            <span class="mx-1 text-gray-400">—</span>
                            <span class="text-gray-600 dark:text-gray-400">{{ $tx->user?->username ?? '—' }}</span>
                            <span class="mx-1 text-gray-400">|</span>
                            <span class="font-medium">{{ number_format($tx->amount_toman) }} تومان</span>
                        </div>
                        <a href="{{ route('filament.zed-admin.resources.payment-transactions.edit', $tx) }}"
                           class="rounded px-2 py-0.5 bg-yellow-100 text-yellow-700 hover:bg-yellow-200 dark:bg-yellow-900/30 dark:text-yellow-400">
                            بررسی
                        </a>
                    </div>
                @empty
                    <p class="text-center text-sm text-green-600 dark:text-green-400 py-2">✅ پرداخت قدیمی در انتظار وجود ندارد</p>
                @endforelse
            </div>
        </div>

        {{-- Recent failed --}}
        <div class="rounded-xl border border-gray-200 bg-gray-50 shadow-sm dark:border-gray-700 dark:bg-gray-800">
            <div class="border-b border-gray-200 px-5 py-3 dark:border-gray-700">
                <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">
                    پرداخت‌های ناموفق اخیر (۳ روز گذشته)
                </span>
            </div>
            <div class="p-4 zp-scroll-box">
                @forelse ($recentFailed as $tx)
                    <div class="mb-2 flex items-center justify-between rounded-lg bg-white px-3 py-2 text-xs dark:bg-gray-900">
                        <div>
                            <span class="font-mono text-gray-700 dark:text-gray-300">#{{ $tx->id }}</span>
                            <span class="mx-1 text-gray-400">—</span>
                            <span class="text-gray-500 dark:text-gray-400">{{ $tx->user?->username ?? '—' }}</span>
                            <span class="mx-1 text-gray-300">|</span>
                            <span class="text-gray-600 dark:text-gray-400 capitalize">{{ $tx->provider ?? '—' }}</span>
                        </div>
                        <span class="rounded px-2 py-0.5 bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400">
                            {{ PaymentTransaction::allStatuses()[$tx->status] ?? $tx->status }}
                        </span>
                    </div>
                @empty
                    <p class="text-center text-sm text-green-600 dark:text-green-400 py-2">✅ پرداخت ناموفق اخیری وجود ندارد</p>
                @endforelse
            </div>
        </div>

    </div>

    {{-- Chart.js loader fallback --}}
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const scriptTag = document.querySelector('script[src*="chart.js"]');
            if (!scriptTag && !window.Chart) {
                const s = document.createElement('script');
                s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js';
                s.onload = () => window.dispatchEvent(new Event('chartjs-loaded'));
                document.head.appendChild(s);
            }
        });
    </script>

</x-filament-panels::page>
