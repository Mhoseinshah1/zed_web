<?php

namespace Database\Seeders;

use App\Models\SiteText;
use Illuminate\Database\Seeder;

class WalletSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            [
                'key'        => 'wallet_enabled',
                'group'      => 'wallet',
                'label'      => 'فعال بودن کیف پول',
                'value'      => '1',
                'type'       => 'boolean',
                'sort_order' => 100,
            ],
            [
                'key'        => 'wallet_payment_enabled',
                'group'      => 'wallet',
                'label'      => 'امکان پرداخت سفارش با کیف پول',
                'value'      => '1',
                'type'       => 'boolean',
                'sort_order' => 101,
            ],
            [
                'key'        => 'wallet_topup_enabled',
                'group'      => 'wallet',
                'label'      => 'امکان شارژ کیف پول',
                'value'      => '1',
                'type'       => 'boolean',
                'sort_order' => 102,
            ],
            [
                'key'        => 'wallet_topup_nowpayments_enabled',
                'group'      => 'wallet',
                'label'      => 'شارژ کیف پول با NOWPayments',
                'value'      => '1',
                'type'       => 'boolean',
                'sort_order' => 103,
            ],
            [
                'key'        => 'wallet_topup_centralpay_enabled',
                'group'      => 'wallet',
                'label'      => 'شارژ کیف پول با CentralPay',
                'value'      => '0',
                'type'       => 'boolean',
                'sort_order' => 104,
            ],
            [
                'key'        => 'wallet_min_topup_amount',
                'group'      => 'wallet',
                'label'      => 'حداقل مبلغ شارژ کیف پول',
                'value'      => '100000',
                'type'       => 'number',
                'sort_order' => 105,
            ],
            [
                'key'        => 'wallet_max_topup_amount',
                'group'      => 'wallet',
                'label'      => 'حداکثر مبلغ شارژ کیف پول',
                'value'      => '',
                'type'       => 'number',
                'sort_order' => 106,
                'description' => 'خالی = بدون محدودیت',
            ],
            [
                'key'        => 'wallet_currency',
                'group'      => 'wallet',
                'label'      => 'واحد پول کیف پول',
                'value'      => 'IRT',
                'type'       => 'text',
                'sort_order' => 107,
            ],
            [
                'key'        => 'wallet_topup_preset_amounts',
                'group'      => 'wallet',
                'label'      => 'مبالغ پیشنهادی شارژ کیف پول',
                'value'      => '100000,250000,500000,1000000,2000000',
                'type'       => 'text',
                'sort_order' => 108,
            ],
            [
                'key'        => 'wallet_admin_adjustment_requires_note',
                'group'      => 'wallet',
                'label'      => 'اجباری بودن توضیح برای تغییر دستی موجودی',
                'value'      => '1',
                'type'       => 'boolean',
                'sort_order' => 109,
            ],
        ];

        foreach ($settings as $item) {
            SiteText::firstOrCreate(
                ['key' => $item['key']],
                $item
            );
        }
    }
}
