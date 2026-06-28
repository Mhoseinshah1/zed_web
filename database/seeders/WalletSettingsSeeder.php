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
                'label'      => 'کیف پول فعال',
                'value'      => '0',
                'type'       => 'boolean',
                'sort_order' => 100,
            ],
            [
                'key'        => 'wallet_payment_enabled',
                'group'      => 'wallet',
                'label'      => 'پرداخت سفارش از کیف پول فعال',
                'value'      => '0',
                'type'       => 'boolean',
                'sort_order' => 101,
            ],
            [
                'key'        => 'wallet_topup_enabled',
                'group'      => 'wallet',
                'label'      => 'شارژ کیف پول فعال',
                'value'      => '0',
                'type'       => 'boolean',
                'sort_order' => 102,
            ],
            [
                'key'        => 'wallet_topup_nowpayments_enabled',
                'group'      => 'wallet',
                'label'      => 'شارژ از طریق NOWPayments فعال',
                'value'      => '0',
                'type'       => 'boolean',
                'sort_order' => 103,
            ],
            [
                'key'        => 'wallet_topup_centralpay_enabled',
                'group'      => 'wallet',
                'label'      => 'شارژ از طریق CentralPay فعال',
                'value'      => '0',
                'type'       => 'boolean',
                'sort_order' => 104,
            ],
            [
                'key'        => 'wallet_min_topup_amount',
                'group'      => 'wallet',
                'label'      => 'حداقل مبلغ شارژ (تومان)',
                'value'      => '10000',
                'type'       => 'number',
                'sort_order' => 105,
            ],
            [
                'key'        => 'wallet_max_topup_amount',
                'group'      => 'wallet',
                'label'      => 'حداکثر مبلغ شارژ (تومان)',
                'value'      => '10000000',
                'type'       => 'number',
                'sort_order' => 106,
            ],
            [
                'key'        => 'wallet_currency',
                'group'      => 'wallet',
                'label'      => 'واحد ارز کیف پول',
                'value'      => 'IRT',
                'type'       => 'text',
                'sort_order' => 107,
            ],
            [
                'key'        => 'wallet_topup_preset_amounts',
                'group'      => 'wallet',
                'label'      => 'مبالغ پیش‌فرض شارژ (با کاما جدا کنید)',
                'value'      => '50000,100000,200000,500000',
                'type'       => 'text',
                'sort_order' => 108,
            ],
            [
                'key'        => 'wallet_admin_adjustment_requires_note',
                'group'      => 'wallet',
                'label'      => 'توضیح اجباری برای تعدیل دستی ادمین',
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
