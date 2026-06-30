<?php

namespace App\Console\Commands;

use App\Services\Telegram\DailyReportService;
use Illuminate\Console\Command;

class TelegramDailyReportCommand extends Command
{
    protected $signature = 'zedproxy:telegram-daily-report';

    protected $description = 'Send the daily admin summary to the Telegram "daily report" topic.';

    public function handle(DailyReportService $report): int
    {
        $report->send();
        $this->info('Daily report dispatched.');
        return self::SUCCESS;
    }
}
