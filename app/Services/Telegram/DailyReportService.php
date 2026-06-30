<?php

namespace App\Services\Telegram;

use App\Models\BackupLog;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Models\WalletTransaction;

/**
 * Builds and sends the daily admin summary into the "daily_report" topic.
 * Safe summaries / numbers only.
 */
class DailyReportService
{
    public function __construct(private readonly TelegramAdminNotifier $notifier) {}

    /** Build + send the report. Never throws. */
    public function send(): void
    {
        try {
            $this->notifier->send('daily_report', 'daily_report', '🗓 گزارش روزانه', $this->buildText());
        } catch (\Throwable $e) {
            TelegramSettings::safeLog('daily report failed', ['error' => class_basename($e)]);
        }
    }

    public function buildText(): string
    {
        $today = now()->startOfDay();

        $paid       = Order::where('payment_status', Order::PAYMENT_PAID)->where('paid_at', '>=', $today);
        $sales      = (int) (clone $paid)->sum('final_price_toman');
        $paidCount  = (clone $paid)->count();
        $failedPay  = PaymentTransaction::where('status', PaymentTransaction::STATUS_FAILED)->where('created_at', '>=', $today)->count();
        $topups     = (int) WalletTransaction::where('type', WalletTransaction::TYPE_TOPUP)
            ->where('status', WalletTransaction::STATUS_COMPLETED)->where('created_at', '>=', $today)->sum('amount_toman');

        $newUsers    = User::where('created_at', '>=', $today)->count();
        $newServices = UserService::where('created_at', '>=', $today)->count();
        $newTickets  = SupportTicket::where('created_at', '>=', $today)->count();
        $openTickets = SupportTicket::whereIn('status', [
            SupportTicket::STATUS_OPEN, SupportTicket::STATUS_WAITING_ADMIN,
            SupportTicket::STATUS_WAITING_USER, SupportTicket::STATUS_ANSWERED,
        ])->count();

        $failedOps = Order::whereIn('status', [
            Order::STATUS_PROVISIONING_FAILED, Order::STATUS_RENEWAL_FAILED,
            Order::STATUS_ADDON_FAILED, Order::STATUS_FAILED,
        ])->count();

        $panelsOffline = VpnPanel::where('is_active', true)->where('health_status', VpnPanel::HEALTH_OFFLINE)->count();

        $lastBackup = BackupLog::latestLog();
        $backupLine = $lastBackup
            ? match ($lastBackup->status) {
                BackupLog::STATUS_SUCCESS => '🟢 موفق (' . $lastBackup->sizeMb() . ' MB) — ' . $lastBackup->updated_at->format('Y/m/d H:i'),
                BackupLog::STATUS_FAILED  => '🔴 ناموفق — ' . $lastBackup->updated_at->format('Y/m/d H:i'),
                default                   => '⏳ در حال اجرا',
            }
            : '—';

        return "🗓 <b>گزارش روزانه</b> — " . now()->format('Y/m/d') . "\n\n"
            . "💵 فروش: " . number_format($sales) . " تومان\n"
            . "✅ سفارش پرداخت‌شده: {$paidCount}\n"
            . "❌ پرداخت ناموفق: {$failedPay}\n"
            . "👛 شارژ کیف پول: " . number_format($topups) . " تومان\n"
            . "👥 کاربر جدید: {$newUsers}\n"
            . "🚀 سرویس جدید: {$newServices}\n"
            . "🎫 تیکت جدید: {$newTickets} (باز: {$openTickets})\n"
            . "⚠️ عملیات ناموفق: {$failedOps}\n"
            . "🖥 پنل آفلاین: {$panelsOffline}\n"
            . "💾 آخرین بکاپ: {$backupLine}";
    }
}
