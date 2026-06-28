<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use App\Models\SiteText;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function index()
    {
        $user         = auth()->user();
        $transactions = $user->walletTransactions()->latest()->paginate(20);

        $walletEnabled = SiteText::get('wallet_enabled', '0') === '1';
        $topupEnabled  = $walletEnabled && SiteText::get('wallet_topup_enabled', '0') === '1';

        return view('dashboard.wallet', compact('user', 'transactions', 'walletEnabled', 'topupEnabled'));
    }

    public function topupForm()
    {
        $walletEnabled = SiteText::get('wallet_enabled', '0') === '1';
        $topupEnabled  = SiteText::get('wallet_topup_enabled', '0') === '1';

        abort_unless($walletEnabled && $topupEnabled, 404);

        $user = auth()->user();

        $presetAmounts = collect(explode(',', SiteText::get('wallet_topup_preset_amounts', '100000,250000,500000,1000000,2000000')))
            ->map(fn ($v) => (int) trim($v))
            ->filter(fn ($v) => $v > 0)
            ->values()
            ->all();

        $minAmount    = max(1, (int) SiteText::get('wallet_min_topup_amount', '100000'));
        $maxAmountRaw = SiteText::get('wallet_max_topup_amount', '');
        $maxAmount    = ($maxAmountRaw !== '' && (int) $maxAmountRaw > 0) ? max($minAmount, (int) $maxAmountRaw) : null;

        $methods = collect();

        if (SiteText::get('wallet_topup_nowpayments_enabled', '0') === '1') {
            $np = PaymentMethod::where('type', PaymentMethod::TYPE_NOWPAYMENTS)
                ->where('is_active', true)->first();
            if ($np) {
                $methods->push($np);
            }
        }

        if (SiteText::get('wallet_topup_centralpay_enabled', '0') === '1') {
            $cp = PaymentMethod::where('type', PaymentMethod::TYPE_CENTRALPAY)
                ->where('is_active', true)->first();
            if ($cp) {
                $methods->push($cp);
            }
        }

        return view('dashboard.wallet-topup', compact('user', 'methods', 'presetAmounts', 'minAmount', 'maxAmount'));
    }

    public function processTopup(Request $request)
    {
        $walletEnabled = SiteText::get('wallet_enabled', '0') === '1';
        $topupEnabled  = SiteText::get('wallet_topup_enabled', '0') === '1';

        abort_unless($walletEnabled && $topupEnabled, 403);

        $minAmount    = max(1, (int) SiteText::get('wallet_min_topup_amount', '100000'));
        $maxAmountRaw = SiteText::get('wallet_max_topup_amount', '');
        $maxAmount    = ($maxAmountRaw !== '' && (int) $maxAmountRaw > 0) ? max($minAmount, (int) $maxAmountRaw) : null;

        $amountRules = ['required', 'integer', "min:{$minAmount}"];
        if ($maxAmount !== null) {
            $amountRules[] = "max:{$maxAmount}";
        }

        $request->validate([
            'payment_method_id' => ['required', 'exists:payment_methods,id'],
            'amount'            => $amountRules,
        ]);

        $method      = PaymentMethod::findOrFail($request->payment_method_id);
        $amountToman = (int) $request->amount;
        $user        = auth()->user();

        if ($method->isNowPayments()) {
            abort_unless(
                SiteText::get('wallet_topup_nowpayments_enabled', '0') === '1' && $method->is_active,
                422
            );
            return app(NowPaymentsController::class)
                ->createWalletTopup($user, $amountToman, $method);
        }

        if ($method->isCentralPay()) {
            abort_unless(
                SiteText::get('wallet_topup_centralpay_enabled', '0') === '1' && $method->is_active,
                422
            );
            return app(CentralPayController::class)
                ->initiateTopup($user, $amountToman, $method);
        }

        return back()->withErrors(['payment_method_id' => 'روش پرداخت انتخابی برای شارژ کیف پول مجاز نیست.']);
    }
}
