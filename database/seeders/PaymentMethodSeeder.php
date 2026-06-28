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

        // CentralPay — seed only if missing; all config is now managed from /zed-admin
        PaymentMethod::firstOrCreate(
            ['slug' => 'centralpay'],
            [
                'title'       => 'پرداخت ریالی',
                'type'        => PaymentMethod::TYPE_CENTRALPAY,
                'description' => 'پرداخت ریالی از طریق درگاه CentralPay',
                'is_active'   => (bool) env('CENTRALPAY_ENABLED', false),
                'sort_order'  => 3,
            ]
        );

        // One-time migration: import .env CentralPay values into admin config if not already set.
        // After this runs once, .env values are no longer required.
        // Never overwrites admin-edited values.
        $cpMethod = PaymentMethod::where('slug', 'centralpay')->first();
        if ($cpMethod) {
            $updates      = [];
            $config       = $cpMethod->config ?? [];
            $configChanged = false;

            // Import api_key from env only if DB column is NULL (not yet configured in admin)
            $rawApiKey = $cpMethod->getRawOriginal('api_key');
            if ($rawApiKey === null && ! empty(env('CENTRALPAY_API_KEY'))) {
                $updates['api_key'] = env('CENTRALPAY_API_KEY');
            }

            // Import config values from env only if not already set in admin
            $envConfig = [
                'base_url'      => env('CENTRALPAY_BASE_URL'),
                'type'          => env('CENTRALPAY_TYPE'),
                'amount_unit'   => env('CENTRALPAY_AMOUNT_UNIT'),
                'callback_path' => env('CENTRALPAY_CALLBACK_PATH'),
            ];

            foreach ($envConfig as $key => $envValue) {
                if (! empty($envValue) && ! array_key_exists($key, $config)) {
                    $config[$key]  = $envValue;
                    $configChanged = true;
                }
            }

            if ($configChanged) {
                $updates['config'] = $config;
            }

            if ($updates) {
                $cpMethod->update($updates);
            }
        }
    }
}
