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

        // NOWPayments — seed only if missing; credentials must be set manually in admin
        PaymentMethod::firstOrCreate(
            ['slug' => 'nowpayments'],
            [
                'title'       => 'پرداخت کریپتو (NOWPayments)',
                'type'        => PaymentMethod::TYPE_NOWPAYMENTS,
                'description' => 'پرداخت با ارزهای دیجیتال از طریق درگاه NOWPayments',
                'is_active'   => false,
                'sort_order'  => 5,
                'config'      => [
                    'sandbox'              => true,
                    'site_currency'        => 'IRT',
                    'price_currency'       => 'usd',
                    'default_pay_currency' => 'usdttrc20',
                    'exchange_rate_usd'    => 0,
                ],
            ]
        );

        // CentralPay — seed only if missing; activate via admin panel and set CENTRALPAY_ENABLED=true
        PaymentMethod::firstOrCreate(
            ['slug' => 'centralpay'],
            [
                'title'       => 'پرداخت ریالی',
                'type'        => PaymentMethod::TYPE_CENTRALPAY,
                'description' => 'پرداخت ریالی از طریق درگاه CentralPay',
                'is_active'   => false,
                'sort_order'  => 3,
            ]
        );
    }
}
