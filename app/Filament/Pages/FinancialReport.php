<?php

namespace App\Filament\Pages;

use App\Models\DiscountRedemption;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Models\WalletTransaction;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FinancialReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.financial-report';

    protected static ?string $navigationIcon  = 'heroicon-o-chart-bar-square';
    protected static ?string $navigationGroup = 'مالی';
    protected static ?string $navigationLabel = 'گزارش مالی';
    protected static ?string $title           = 'داشبورد مالی';
    protected static ?string $slug            = 'reports/financial';
    protected static ?int    $navigationSort  = 10;

    public string $dateFrom = '';
    public string $dateTo   = '';

    public function mount(): void
    {
        $this->dateFrom = now()->startOfMonth()->format('Y-m-d');
        $this->dateTo   = now()->format('Y-m-d');

        $this->form->fill([
            'dateFrom' => $this->dateFrom,
            'dateTo'   => $this->dateTo,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\DatePicker::make('dateFrom')
                    ->label('از تاریخ')
                    ->displayFormat('Y-m-d')
                    ->native(false)
                    ->default(now()->startOfMonth()),

                Forms\Components\DatePicker::make('dateTo')
                    ->label('تا تاریخ')
                    ->displayFormat('Y-m-d')
                    ->native(false)
                    ->default(now()),
            ])
            ->columns(2)
            ->statePath('');
    }

    public function applyFilter(): void
    {
        $data = $this->form->getState();
        $this->dateFrom = $data['dateFrom'] ?? now()->startOfMonth()->format('Y-m-d');
        $this->dateTo   = $data['dateTo']   ?? now()->format('Y-m-d');
    }

    public function resetToToday(): void
    {
        $this->dateFrom = now()->format('Y-m-d');
        $this->dateTo   = now()->format('Y-m-d');
        $this->form->fill(['dateFrom' => $this->dateFrom, 'dateTo' => $this->dateTo]);
    }

    public function resetToMonth(): void
    {
        $this->dateFrom = now()->startOfMonth()->format('Y-m-d');
        $this->dateTo   = now()->format('Y-m-d');
        $this->form->fill(['dateFrom' => $this->dateFrom, 'dateTo' => $this->dateTo]);
    }

    // ─── Date helpers ───────────────────────────────────────────────────────

    private function from(): Carbon
    {
        return Carbon::parse($this->dateFrom)->startOfDay();
    }

    private function to(): Carbon
    {
        return Carbon::parse($this->dateTo)->endOfDay();
    }

    // ─── KPI: Sales ─────────────────────────────────────────────────────────

    public function getSalesToday(): int
    {
        return Order::where('payment_status', Order::PAYMENT_PAID)
            ->whereDate('paid_at', today())
            ->sum('final_price_toman');
    }

    public function getSalesMonth(): int
    {
        return Order::where('payment_status', Order::PAYMENT_PAID)
            ->whereBetween('paid_at', [now()->startOfMonth()->startOfDay(), now()->endOfDay()])
            ->sum('final_price_toman');
    }

    public function getSalesRange(): int
    {
        return Order::where('payment_status', Order::PAYMENT_PAID)
            ->whereBetween('paid_at', [$this->from(), $this->to()])
            ->sum('final_price_toman');
    }

    public function getPaidOrdersToday(): int
    {
        return Order::where('payment_status', Order::PAYMENT_PAID)
            ->whereDate('paid_at', today())
            ->count();
    }

    public function getPaidOrdersRange(): int
    {
        return Order::where('payment_status', Order::PAYMENT_PAID)
            ->whereBetween('paid_at', [$this->from(), $this->to()])
            ->count();
    }

    // ─── KPI: Payments ──────────────────────────────────────────────────────

    public function getPendingPaymentsCount(): int
    {
        return PaymentTransaction::whereIn('status', [
            PaymentTransaction::STATUS_PENDING,
            PaymentTransaction::STATUS_SUBMITTED,
            PaymentTransaction::STATUS_WAITING,
            PaymentTransaction::STATUS_CONFIRMING,
        ])->count();
    }

    public function getFailedPaymentsCount(): int
    {
        return PaymentTransaction::whereIn('status', [
            PaymentTransaction::STATUS_FAILED,
            PaymentTransaction::STATUS_REJECTED,
            PaymentTransaction::STATUS_EXPIRED,
            PaymentTransaction::STATUS_CANCELLED,
        ])->whereBetween('created_at', [$this->from(), $this->to()])
            ->count();
    }

    public function getProvisioningFailedCount(): int
    {
        return Order::where('status', Order::STATUS_PROVISIONING_FAILED)->count();
    }

    public function getRenewalOrdersRange(): int
    {
        return Order::where('order_type', Order::TYPE_RENEWAL)
            ->where('payment_status', Order::PAYMENT_PAID)
            ->whereBetween('paid_at', [$this->from(), $this->to()])
            ->count();
    }

    public function getRenewalSalesRange(): int
    {
        return Order::where('order_type', Order::TYPE_RENEWAL)
            ->where('payment_status', Order::PAYMENT_PAID)
            ->whereBetween('paid_at', [$this->from(), $this->to()])
            ->sum('final_price_toman');
    }

    public function getRenewalFailedCount(): int
    {
        return Order::where('status', Order::STATUS_RENEWAL_FAILED)->count();
    }

    public function getNewServiceOrdersRange(): int
    {
        return Order::where('order_type', Order::TYPE_NEW_SERVICE)
            ->where('payment_status', Order::PAYMENT_PAID)
            ->whereBetween('paid_at', [$this->from(), $this->to()])
            ->count();
    }

    public function getNewServiceSalesRange(): int
    {
        return Order::where('order_type', Order::TYPE_NEW_SERVICE)
            ->where('payment_status', Order::PAYMENT_PAID)
            ->whereBetween('paid_at', [$this->from(), $this->to()])
            ->sum('final_price_toman');
    }

    public function getExtraTrafficOrdersRange(): int
    {
        return Order::where('order_type', Order::TYPE_EXTRA_TRAFFIC)
            ->where('payment_status', Order::PAYMENT_PAID)
            ->whereBetween('paid_at', [$this->from(), $this->to()])
            ->count();
    }

    public function getExtraTrafficSalesRange(): int
    {
        return (int) Order::where('order_type', Order::TYPE_EXTRA_TRAFFIC)
            ->where('payment_status', Order::PAYMENT_PAID)
            ->whereBetween('paid_at', [$this->from(), $this->to()])
            ->sum('final_price_toman');
    }

    public function getExtraTimeOrdersRange(): int
    {
        return Order::where('order_type', Order::TYPE_EXTRA_TIME)
            ->where('payment_status', Order::PAYMENT_PAID)
            ->whereBetween('paid_at', [$this->from(), $this->to()])
            ->count();
    }

    public function getExtraTimeSalesRange(): int
    {
        return (int) Order::where('order_type', Order::TYPE_EXTRA_TIME)
            ->where('payment_status', Order::PAYMENT_PAID)
            ->whereBetween('paid_at', [$this->from(), $this->to()])
            ->sum('final_price_toman');
    }

    public function getRenewalCashbackRange(): int
    {
        return (int) WalletTransaction::where('type', WalletTransaction::TYPE_RENEWAL_CASHBACK)
            ->where('direction', WalletTransaction::DIRECTION_CREDIT)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->whereBetween('created_at', [$this->from(), $this->to()])
            ->sum('amount_toman');
    }

    // ─── KPI: Wallet ────────────────────────────────────────────────────────

    public function getTotalWalletBalance(): int
    {
        return (int) User::sum('wallet_balance_toman');
    }

    public function getWalletTopupToday(): int
    {
        return WalletTransaction::where('type', WalletTransaction::TYPE_TOPUP)
            ->where('direction', WalletTransaction::DIRECTION_CREDIT)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->whereDate('created_at', today())
            ->sum('amount_toman');
    }

    public function getWalletTopupRange(): int
    {
        return WalletTransaction::where('type', WalletTransaction::TYPE_TOPUP)
            ->where('direction', WalletTransaction::DIRECTION_CREDIT)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->whereBetween('created_at', [$this->from(), $this->to()])
            ->sum('amount_toman');
    }

    public function getWalletTopupMonth(): int
    {
        return WalletTransaction::where('type', WalletTransaction::TYPE_TOPUP)
            ->where('direction', WalletTransaction::DIRECTION_CREDIT)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->whereBetween('created_at', [now()->startOfMonth()->startOfDay(), now()->endOfDay()])
            ->sum('amount_toman');
    }

    public function getWalletSalesToday(): int
    {
        return WalletTransaction::where('type', WalletTransaction::TYPE_ORDER_PAYMENT)
            ->where('direction', WalletTransaction::DIRECTION_DEBIT)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->whereDate('created_at', today())
            ->sum('amount_toman');
    }

    public function getWalletSalesRange(): int
    {
        return WalletTransaction::where('type', WalletTransaction::TYPE_ORDER_PAYMENT)
            ->where('direction', WalletTransaction::DIRECTION_DEBIT)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->whereBetween('created_at', [$this->from(), $this->to()])
            ->sum('amount_toman');
    }

    public function getWalletSpendingMonth(): int
    {
        return WalletTransaction::where('type', WalletTransaction::TYPE_ORDER_PAYMENT)
            ->where('direction', WalletTransaction::DIRECTION_DEBIT)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->whereBetween('created_at', [now()->startOfMonth()->startOfDay(), now()->endOfDay()])
            ->sum('amount_toman');
    }

    // ─── KPI: Provider Sales ────────────────────────────────────────────────

    public function getNowPaymentsSalesToday(): int
    {
        return PaymentTransaction::where('provider', 'nowpayments')
            ->where('payment_purpose', 'order_payment')
            ->where('status', PaymentTransaction::STATUS_APPROVED)
            ->whereDate('paid_at', today())
            ->sum('amount_toman');
    }

    public function getNowPaymentsSalesRange(): int
    {
        return PaymentTransaction::where('provider', 'nowpayments')
            ->where('payment_purpose', 'order_payment')
            ->where('status', PaymentTransaction::STATUS_APPROVED)
            ->whereBetween('paid_at', [$this->from(), $this->to()])
            ->sum('amount_toman');
    }

    public function getCentralPaySalesToday(): int
    {
        return PaymentTransaction::where('provider', 'centralpay')
            ->where('payment_purpose', 'order_payment')
            ->where('status', PaymentTransaction::STATUS_APPROVED)
            ->whereDate('paid_at', today())
            ->sum('amount_toman');
    }

    public function getCentralPaySalesRange(): int
    {
        return PaymentTransaction::where('provider', 'centralpay')
            ->where('payment_purpose', 'order_payment')
            ->where('status', PaymentTransaction::STATUS_APPROVED)
            ->whereBetween('paid_at', [$this->from(), $this->to()])
            ->sum('amount_toman');
    }

    // ─── Provider breakdown table ────────────────────────────────────────────

    public function getProviderBreakdown(): array
    {
        $from = $this->from();
        $to   = $this->to();

        $walletSales = WalletTransaction::where('type', WalletTransaction::TYPE_ORDER_PAYMENT)
            ->where('direction', WalletTransaction::DIRECTION_DEBIT)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(amount_toman),0) as total')
            ->first();

        $rows = PaymentTransaction::where('payment_purpose', 'order_payment')
            ->where('status', PaymentTransaction::STATUS_APPROVED)
            ->whereBetween('paid_at', [$from, $to])
            ->selectRaw("provider, COUNT(*) as cnt, COALESCE(SUM(amount_toman),0) as total")
            ->groupBy('provider')
            ->get()
            ->keyBy('provider');

        $totalSales = max(1,
            (int)($walletSales->total ?? 0)
            + (int)($rows->get('nowpayments')->total ?? 0)
            + (int)($rows->get('centralpay')->total ?? 0)
            + (int)($rows->get('manual')->total ?? 0)
        );

        return [
            [
                'label'   => 'کیف پول',
                'count'   => (int)($walletSales->cnt ?? 0),
                'total'   => (int)($walletSales->total ?? 0),
                'share'   => round((int)($walletSales->total ?? 0) / $totalSales * 100, 1),
                'color'   => 'info',
            ],
            [
                'label'   => 'NOWPayments',
                'count'   => (int)($rows->get('nowpayments')->cnt ?? 0),
                'total'   => (int)($rows->get('nowpayments')->total ?? 0),
                'share'   => round((int)($rows->get('nowpayments')->total ?? 0) / $totalSales * 100, 1),
                'color'   => 'warning',
            ],
            [
                'label'   => 'CentralPay',
                'count'   => (int)($rows->get('centralpay')->cnt ?? 0),
                'total'   => (int)($rows->get('centralpay')->total ?? 0),
                'share'   => round((int)($rows->get('centralpay')->total ?? 0) / $totalSales * 100, 1),
                'color'   => 'success',
            ],
            [
                'label'   => 'دستی / سایر',
                'count'   => (int)($rows->get('manual')->cnt ?? 0),
                'total'   => (int)($rows->get('manual')->total ?? 0),
                'share'   => round((int)($rows->get('manual')->total ?? 0) / $totalSales * 100, 1),
                'color'   => 'gray',
            ],
        ];
    }

    // ─── Wallet summary ─────────────────────────────────────────────────────

    public function getWalletSummary(): array
    {
        $topupCount = WalletTransaction::where('type', WalletTransaction::TYPE_TOPUP)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->whereBetween('created_at', [$this->from(), $this->to()])
            ->count();

        $adminCount = WalletTransaction::whereIn('type', [
            WalletTransaction::TYPE_MANUAL_CREDIT,
            WalletTransaction::TYPE_MANUAL_DEBIT,
        ])->whereBetween('created_at', [$this->from(), $this->to()])
            ->count();

        return [
            'total_balance'      => $this->getTotalWalletBalance(),
            'topup_range'        => $this->getWalletTopupRange(),
            'topup_month'        => $this->getWalletTopupMonth(),
            'spending_range'     => $this->getWalletSalesRange(),
            'spending_month'     => $this->getWalletSpendingMonth(),
            'topup_count'        => $topupCount,
            'admin_count'        => $adminCount,
        ];
    }

    // ─── Risky items ─────────────────────────────────────────────────────────

    public function getProvisioningFailedOrders(): \Illuminate\Support\Collection
    {
        return Order::where('status', Order::STATUS_PROVISIONING_FAILED)
            ->with('user')
            ->latest('updated_at')
            ->limit(10)
            ->get();
    }

    public function getOldPendingPayments(): \Illuminate\Support\Collection
    {
        return PaymentTransaction::whereIn('status', [
            PaymentTransaction::STATUS_PENDING,
            PaymentTransaction::STATUS_SUBMITTED,
        ])->where('created_at', '<', now()->subHours(12))
            ->with('user', 'order')
            ->latest()
            ->limit(10)
            ->get();
    }

    public function getRecentFailedPayments(): \Illuminate\Support\Collection
    {
        return PaymentTransaction::whereIn('status', [
            PaymentTransaction::STATUS_FAILED,
            PaymentTransaction::STATUS_REJECTED,
            PaymentTransaction::STATUS_EXPIRED,
        ])->where('created_at', '>=', now()->subDays(3))
            ->with('user', 'order')
            ->latest()
            ->limit(10)
            ->get();
    }

    public function getPaidWithoutService(): \Illuminate\Support\Collection
    {
        return Order::where('payment_status', Order::PAYMENT_PAID)
            ->whereDoesntHave('service')
            ->whereNotIn('status', [Order::STATUS_CANCELLED, Order::STATUS_FAILED])
            ->where('paid_at', '<', now()->subHours(2))
            ->with('user')
            ->latest('paid_at')
            ->limit(10)
            ->get();
    }

    // ─── Discounts ───────────────────────────────────────────────────────────

    public function getTotalDiscountsToday(): int
    {
        return (int) DiscountRedemption::where('status', DiscountRedemption::STATUS_USED)
            ->whereDate('used_at', today())
            ->sum('discount_amount');
    }

    public function getTotalDiscountsRange(): int
    {
        return (int) DiscountRedemption::where('status', DiscountRedemption::STATUS_USED)
            ->whereBetween('used_at', [$this->from(), $this->to()])
            ->sum('discount_amount');
    }

    public function getDiscountCountRange(): int
    {
        return DiscountRedemption::where('status', DiscountRedemption::STATUS_USED)
            ->whereBetween('used_at', [$this->from(), $this->to()])
            ->count();
    }

    // ─── Charts ──────────────────────────────────────────────────────────────

    public function getSales7DaysChartData(): array
    {
        $days   = collect();
        $labels = [];
        $data   = [];

        $rows = Order::where('payment_status', Order::PAYMENT_PAID)
            ->where('paid_at', '>=', now()->subDays(6)->startOfDay())
            ->selectRaw("DATE(paid_at) as day, COALESCE(SUM(final_price_toman),0) as total")
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('total', 'day');

        for ($i = 6; $i >= 0; $i--) {
            $date     = now()->subDays($i)->format('Y-m-d');
            $labels[] = now()->subDays($i)->format('m/d');
            $data[]   = (int)($rows[$date] ?? 0);
        }

        return compact('labels', 'data');
    }

    public function getSales30DaysChartData(): array
    {
        $labels = [];
        $data   = [];

        $rows = Order::where('payment_status', Order::PAYMENT_PAID)
            ->where('paid_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw("DATE(paid_at) as day, COALESCE(SUM(final_price_toman),0) as total")
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('total', 'day');

        for ($i = 29; $i >= 0; $i--) {
            $date     = now()->subDays($i)->format('Y-m-d');
            $labels[] = now()->subDays($i)->format('m/d');
            $data[]   = (int)($rows[$date] ?? 0);
        }

        return compact('labels', 'data');
    }

    public function getWalletTopup30DaysChartData(): array
    {
        $labels = [];
        $data   = [];

        $rows = WalletTransaction::where('type', WalletTransaction::TYPE_TOPUP)
            ->where('direction', WalletTransaction::DIRECTION_CREDIT)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw("DATE(created_at) as day, COALESCE(SUM(amount_toman),0) as total")
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('total', 'day');

        for ($i = 29; $i >= 0; $i--) {
            $date     = now()->subDays($i)->format('Y-m-d');
            $labels[] = now()->subDays($i)->format('m/d');
            $data[]   = (int)($rows[$date] ?? 0);
        }

        return compact('labels', 'data');
    }

    public function getPaymentProviderChartData(): array
    {
        $from = $this->from();
        $to   = $this->to();

        $wallet = (int) WalletTransaction::where('type', WalletTransaction::TYPE_ORDER_PAYMENT)
            ->where('direction', WalletTransaction::DIRECTION_DEBIT)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->whereBetween('created_at', [$from, $to])
            ->sum('amount_toman');

        $now = (int) PaymentTransaction::where('provider', 'nowpayments')
            ->where('payment_purpose', 'order_payment')
            ->where('status', PaymentTransaction::STATUS_APPROVED)
            ->whereBetween('paid_at', [$from, $to])
            ->sum('amount_toman');

        $central = (int) PaymentTransaction::where('provider', 'centralpay')
            ->where('payment_purpose', 'order_payment')
            ->where('status', PaymentTransaction::STATUS_APPROVED)
            ->whereBetween('paid_at', [$from, $to])
            ->sum('amount_toman');

        $manual = (int) PaymentTransaction::where('provider', 'manual')
            ->where('payment_purpose', 'order_payment')
            ->where('status', PaymentTransaction::STATUS_APPROVED)
            ->whereBetween('paid_at', [$from, $to])
            ->sum('amount_toman');

        return [
            'labels' => ['کیف پول', 'NOWPayments', 'CentralPay', 'دستی'],
            'data'   => [$wallet, $now, $central, $manual],
        ];
    }

    public function getPaymentStatusChartData(): array
    {
        $from = $this->from();
        $to   = $this->to();

        $rows = PaymentTransaction::whereBetween('created_at', [$from, $to])
            ->selectRaw("
                SUM(CASE WHEN status IN ('approved') THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN status IN ('pending','submitted','waiting','confirming') THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status IN ('failed','rejected') THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired,
                SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) as refunded
            ")
            ->first();

        return [
            'labels' => ['موفق', 'در انتظار', 'ناموفق', 'منقضی', 'مسترد'],
            'data'   => [
                (int)($rows->success  ?? 0),
                (int)($rows->pending  ?? 0),
                (int)($rows->failed   ?? 0),
                (int)($rows->expired  ?? 0),
                (int)($rows->refunded ?? 0),
            ],
        ];
    }
}
