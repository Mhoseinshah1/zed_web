<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\VpnPanel;
use App\Services\Marzban\MarzbanClient;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckMarzbanPanels extends Command
{
    protected $signature = 'zedproxy:check-marzban-panels {--panel-id=}';

    protected $description = 'Health-check Marzban panels via GET /api/system and store online/offline status. Credentials are never logged.';

    public function handle(): int
    {
        $query = VpnPanel::where('type', VpnPanel::TYPE_MARZBAN)->where('is_active', true);
        if ($id = $this->option('panel-id')) {
            $query->where('id', $id);
        }

        $panels = $query->get();
        $this->info("Checking {$panels->count()} panel(s)...");

        foreach ($panels as $panel) {
            $this->checkPanel($panel);
        }

        return self::SUCCESS;
    }

    private function checkPanel(VpnPanel $panel): void
    {
        try {
            $client = new MarzbanClient($panel);
            $system = $client->getSystem();

            $panel->update([
                'last_health_checked_at' => now(),
                'health_status'          => VpnPanel::HEALTH_ONLINE,
                'health_error'           => null,
                'system_info'            => $this->safeSystem($system),
            ]);

            $this->line(" • {$panel->name}: online");
        } catch (\Throwable $e) {
            $safe = $this->sanitize($e->getMessage());

            $panel->update([
                'last_health_checked_at' => now(),
                'health_status'          => VpnPanel::HEALTH_OFFLINE,
                'health_error'           => $safe,
            ]);

            Log::warning('CheckMarzbanPanels: panel offline', ['panel_id' => $panel->id, 'error' => $safe]);

            app(NotificationService::class)->notifyAdmins(
                Notification::TYPE_ADMIN_WARNING,
                ['message' => "پنل Marzban «{$panel->name}» در دسترس نیست: {$safe}"],
                'panel_health_failed:' . $panel->id . ':' . now()->format('YmdH'),
            );

            $this->line(" • {$panel->name}: OFFLINE — {$safe}");
        }
    }

    private function safeSystem(array $system): array
    {
        return collect($system)
            ->only(['version', 'mem_total', 'mem_used', 'cpu_cores', 'cpu_usage', 'total_user', 'users_active'])
            ->all();
    }

    private function sanitize(string $message): string
    {
        $message = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message) ?? $message;
        return mb_substr($message, 0, 500);
    }
}
