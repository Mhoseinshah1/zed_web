<?php

namespace App\Services\Telegram;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\SupportTicket;
use App\Models\User;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Models\WalletTransaction;
use App\Services\Telegram\DailyReportService;
use Illuminate\Support\Facades\DB;

/**
 * Parses an admin Telegram command and replies into the same group/topic.
 *
 * SECURITY: callers (the webhook controller) already verified the secret, the
 * group chat_id and the allowed admin. Replies contain ONLY safe summaries and
 * numbers — never OTP/passwords/tokens/credentials/links/traces. User-provided
 * text (ticket subjects) is escaped for the active parse mode.
 */
class TelegramCommandRouter
{
    public function __construct(
        private readonly TelegramClient $client,
        private readonly TelegramTemplates $templates,
    ) {}

    /** Parse + handle a command, then send the reply. Never throws. */
    public function dispatch(string $text, string $chatId, ?int $threadId = null): void
    {
        try {
            $command = $this->parseCommand($text);
            if ($command === null) {
                return;
            }

            $reply = $this->handle($command);
            if ($reply === '') {
                return;
            }

            $this->client->sendMessage($reply, $threadId, $chatId);
        } catch (\Throwable $e) {
            TelegramSettings::safeLog('command failed', ['error' => class_basename($e)]);
        }
    }

    /** Resolve "/status@Bot extra" → "status". Returns null if not a command. */
    private function parseCommand(string $text): ?string
    {
        $text = trim($text);
        if ($text === '' || $text[0] !== '/') {
            return null;
        }
        $first = explode(' ', $text)[0];        // "/status@Bot"
        $first = explode('@', ltrim($first, '/'))[0]; // "status"
        return strtolower($first);
    }

    private function handle(string $command): string
    {
        return match ($command) {
            'help'              => $this->help(),
            'status'            => $this->status(),
            'finance_today'     => $this->financeToday(),
            'orders_today'      => $this->ordersToday(),
            'open_tickets'      => $this->openTickets(),
            'failed_operations' => $this->failedOperations(),
            'panels'            => $this->panels(),
            'daily_report'      => $this->dailyReport(),
            'backup'            => $this->backup(),
            'backup_status'     => $this->backupStatus(),
            default             => '',
        };
    }

    // ── Command handlers ─────────────────────────────────────────────────────

    private function help(): string
    {
        return "🤖 <b>دستورهای بات مدیریت زدپروکسی</b>\n\n"
            . "/status — وضعیت کلی سیستم\n"
            . "/finance_today — مالی امروز\n"
            . "/orders_today — سفارش‌های امروز\n"
            . "/open_tickets — تیکت‌های باز\n"
            . "/failed_operations — عملیات ناموفق\n"
            . "/panels — سلامت پنل‌های VPN\n"
            . "/daily_report — گزارش روزانه\n"
            . "/backup — بکاپ (فاز بعد)\n"
            . "/backup_status — وضعیت بکاپ (فاز بعد)\n"
            . "/help — همین راهنما";
    }

    private function status(): string
    {
        $db = $this->dbOk() ? '🟢 متصل' : '🔴 قطع';

        $panels  = VpnPanel::query()->where('is_active', true)->get();
        $online  = $panels->where('health_status', VpnPanel::HEALTH_ONLINE)->count();
        $offline = $panels->where('health_status', VpnPanel::HEALTH_OFFLINE)->count();

        $failed = $this->failedOperationsCount();

        return "📊 <b>وضعیت سیستم</b>\n"
            . "🗄 پایگاه داده: {$db}\n"
            . "🖥 پنل‌ها: 🟢 {$online} آنلاین / 🔴 {$offline} آفلاین\n"
            . "⚠️ عملیات ناموفق: {$failed}\n"
            . "🧰 صف: " . config('queue.default') . "\n"
            . "⏰ زمان: " . now()->format('Y/m/d H:i');
    }

    private function financeToday(): string
    {
        $today = now()->startOfDay();

        $paidOrders = Order::where('payment_status', Order::PAYMENT_PAID)
            ->where('paid_at', '>=', $today);
        $sales       = (int) (clone $paidOrders)->sum('final_price_toman');
        $paidCount   = (clone $paidOrders)->count();

        $failedPayments = PaymentTransaction::where('status', PaymentTransaction::STATUS_FAILED)
            ->where('created_at', '>=', $today)->count();

        $topups = (int) WalletTransaction::where('type', WalletTransaction::TYPE_TOPUP)
            ->where('status', WalletTransaction::STATUS_COMPLETED)
            ->where('created_at', '>=', $today)->sum('amount_toman');

        return "💰 <b>مالی امروز</b>\n"
            . "💵 فروش: " . number_format($sales) . " تومان\n"
            . "✅ سفارش پرداخت‌شده: {$paidCount}\n"
            . "❌ پرداخت ناموفق: {$failedPayments}\n"
            . "👛 شارژ کیف پول: " . number_format($topups) . " تومان";
    }

    private function ordersToday(): string
    {
        $today = now()->startOfDay();
        $base  = Order::where('created_at', '>=', $today);

        $total   = (clone $base)->count();
        $paid    = (clone $base)->where('payment_status', Order::PAYMENT_PAID)->count();
        $pending = (clone $base)->where('payment_status', Order::PAYMENT_PENDING)->count();
        $failed  = (clone $base)->whereIn('status', [Order::STATUS_FAILED, Order::STATUS_CANCELLED])->count();

        return "🧾 <b>سفارش‌های امروز</b>\n"
            . "📦 کل: {$total}\n"
            . "✅ پرداخت‌شده: {$paid}\n"
            . "⏳ در انتظار: {$pending}\n"
            . "❌ ناموفق/لغو: {$failed}";
    }

    private function openTickets(): string
    {
        $openStatuses = [
            SupportTicket::STATUS_OPEN,
            SupportTicket::STATUS_WAITING_ADMIN,
            SupportTicket::STATUS_WAITING_USER,
            SupportTicket::STATUS_ANSWERED,
        ];

        $count   = SupportTicket::whereIn('status', $openStatuses)->count();
        $latest  = SupportTicket::whereIn('status', $openStatuses)
            ->latest('last_reply_at')->limit(5)->get();

        $lines = "🎫 <b>تیکت‌های باز</b>\n📨 تعداد: {$count}\n";
        foreach ($latest as $t) {
            $subject = $this->templates->escape((string) ($t->subject ?? '—'));
            $lines .= "• {$t->ticket_number} — {$subject}\n";
        }

        return rtrim($lines);
    }

    private function failedOperations(): string
    {
        $statuses = [
            Order::STATUS_PROVISIONING_FAILED => 'ساخت سرویس',
            Order::STATUS_RENEWAL_FAILED      => 'تمدید',
            Order::STATUS_ADDON_FAILED        => 'حجم/زمان اضافه',
            Order::STATUS_FAILED              => 'سفارش',
        ];

        $lines = "⚠️ <b>عملیات ناموفق</b>\n";
        $total = 0;
        foreach ($statuses as $status => $label) {
            $c = Order::where('status', $status)->count();
            $total += $c;
            $lines .= "• {$label}: {$c}\n";
        }
        $lines .= "Σ مجموع: {$total}";

        return $lines;
    }

    private function panels(): string
    {
        $panels = VpnPanel::query()->orderBy('name')->get();
        if ($panels->isEmpty()) {
            return "🖥 <b>پنل‌های VPN</b>\nهیچ پنلی ثبت نشده است.";
        }

        $lines = "🖥 <b>سلامت پنل‌های VPN</b>\n";
        foreach ($panels as $p) {
            $icon = match ($p->health_status) {
                VpnPanel::HEALTH_ONLINE  => '🟢',
                VpnPanel::HEALTH_OFFLINE => '🔴',
                default                  => '⚪️',
            };
            $name = $this->templates->escape((string) $p->name);
            $type = $this->templates->escape((string) ($p->type ?? '—'));
            $active = $p->is_active ? '' : ' (غیرفعال)';
            $lines .= "{$icon} {$name} [{$type}]{$active}\n";
        }

        return rtrim($lines);
    }

    private function dailyReport(): string
    {
        // Also push it to the dedicated daily-report topic, and reply inline.
        app(DailyReportService::class)->send();
        return app(DailyReportService::class)->buildText();
    }

    private function backup(): string
    {
        $settings = app(\App\Services\Backup\BackupSettings::class);
        if (! $settings->enabled()) {
            return "💾 <b>بکاپ</b>\nقابلیت بکاپ غیرفعال است. از پنل مدیریت آن را فعال کنید.";
        }
        \App\Jobs\RunBackupJob::dispatch(\App\Models\BackupLog::TYPE_MANUAL);
        return "💾 <b>بکاپ شروع شد</b>\nنتیجه در تاپیک «بکاپ و سرور» ارسال می‌شود.";
    }

    private function backupStatus(): string
    {
        $last = \App\Models\BackupLog::latestLog();
        if (! $last) {
            return "💾 <b>وضعیت بکاپ</b>\nهنوز بکاپی انجام نشده است.";
        }
        $status = match ($last->status) {
            \App\Models\BackupLog::STATUS_SUCCESS => '🟢 موفق (' . $last->sizeMb() . ' MB)',
            \App\Models\BackupLog::STATUS_FAILED  => '🔴 ناموفق',
            default                               => '⏳ در حال اجرا',
        };
        return "💾 <b>وضعیت آخرین بکاپ</b>\nوضعیت: {$status}\n🕒 زمان: " . $last->updated_at->format('Y/m/d H:i');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function dbOk(): bool
    {
        try {
            DB::connection()->getPdo();
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function failedOperationsCount(): int
    {
        return Order::whereIn('status', [
            Order::STATUS_PROVISIONING_FAILED,
            Order::STATUS_RENEWAL_FAILED,
            Order::STATUS_ADDON_FAILED,
            Order::STATUS_FAILED,
        ])->count();
    }
}
