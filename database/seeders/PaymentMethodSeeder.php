<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            [
                'slug'        => 'wallet',
                'title'       => 'پرداخت از کیف پول',
                'type'        => PaymentMethod::TYPE_WALLET,
                'description' => 'استفاده از موجودی کیف پول حساب کاربری',
                'is_active'   => true,
                'sort_order'  => 0,
            ],
            [
                'slug'         => 'manual-crypto',
                'title'        => 'پرداخت دستی ارز دیجیتال',
                'type'         => PaymentMethod::TYPE_MANUAL_CRYPTO,
                'description'  => 'پرداخت با USDT یا سایر ارزهای دیجیتال',
                'instructions' => 'پس از پرداخت، کد تراکنش (TXID) را در کادر مربوطه وارد کنید.',
                'account_label' => 'آدرس کیف پول',
                'network'      => 'TRC20',
                'is_active'    => true,
                'sort_order'   => 10,
            ],
            [
                'slug'         => 'manual-stars',
                'title'        => 'پرداخت دستی تلگرام استارز',
                'type'         => PaymentMethod::TYPE_MANUAL_STARS,
                'description'  => 'پرداخت با Telegram Stars از طریق ربات',
                'instructions' => 'پس از ارسال استارز به ربات، شناسه تراکنش را اینجا وارد کنید.',
                'is_active'    => true,
                'sort_order'   => 20,
            ],
        ];

        foreach ($defaults as $data) {
            PaymentMethod::firstOrCreate(
                ['slug' => $data['slug']],
                $data
            );
        }
    }
}
