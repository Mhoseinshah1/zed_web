<?php

namespace App\Console\Commands;

use App\Models\UserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only diagnostic: report user_services rows that share the same order_id
 * (the duplicate-provisioning symptom). Never deletes or modifies anything —
 * production duplicates must be resolved by a human. Exits non-zero when
 * duplicates are found, so it can gate the unique-constraint migration in CI.
 */
class FindDuplicateServicesCommand extends Command
{
    protected $signature = 'zedproxy:find-duplicate-services';

    protected $description = 'Report duplicate UserService rows sharing an order_id (read-only).';

    public function handle(): int
    {
        $groups = DB::table('user_services')
            ->select('order_id', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('order_id')
            ->groupBy('order_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('cnt')
            ->get();

        if ($groups->isEmpty()) {
            $this->info('✓ هیچ سرویس تکراری برای یک سفارش یافت نشد.');
            $this->line('No duplicate services found — every order maps to at most one service.');
            return self::SUCCESS;
        }

        $this->error("⚠ {$groups->count()} سفارش دارای سرویس تکراری است — نیاز به بررسی دستی.");
        $this->newLine();

        $rows = [];
        foreach ($groups as $g) {
            $services = UserService::where('order_id', $g->order_id)
                ->orderBy('id')
                ->get(['id', 'service_number', 'status', 'provision_status', 'remote_username', 'created_at']);

            foreach ($services as $i => $s) {
                $rows[] = [
                    $i === 0 ? (string) $g->order_id : '',
                    $s->id,
                    $s->service_number,
                    $s->status,
                    $s->provision_status,
                    $s->remote_username ?: '—',
                    (string) $s->created_at,
                ];
            }
            $rows[] = ['', '', '', '', '', '', ''];
        }

        $this->table(
            ['order_id', 'service id', 'service_number', 'status', 'provision', 'remote_user', 'created_at'],
            $rows,
        );

        $this->newLine();
        $this->warn('هیچ رکوردی به‌صورت خودکار حذف نشد. لطفاً به‌صورت دستی رکورد صحیح را نگه دارید.');
        $this->line('Nothing was deleted. Keep the correct (usually the provisioned) service and reconcile the rest by hand.');

        return self::FAILURE;
    }
}
