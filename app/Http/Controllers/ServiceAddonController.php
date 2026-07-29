<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PurchaseIntent;
use App\Models\UserService;
use App\Services\Addons\ServiceAddonService;
use App\Services\Orders\OrderIdempotencyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ServiceAddonController extends Controller
{
    public function __construct(
        private readonly ServiceAddonService $addonService,
        private readonly OrderIdempotencyService $idempotency,
    ) {}

    // ── Extra traffic ────────────────────────────────────────────────────────

    public function showTraffic(UserService $service): View|RedirectResponse
    {
        $this->authorize('viewOwned', $service);

        if (! $this->addonService->trafficEnabled()) {
            return $this->back($service, 'خرید حجم اضافه در حال حاضر غیرفعال است.');
        }

        if ($this->addonService->pricePerGb() === null) {
            return $this->back($service, 'قیمت هر گیگ حجم اضافه تنظیم نشده است.');
        }

        if (! $service->traffic_total_gb || $service->traffic_total_gb <= 0) {
            return $this->back($service, 'این سرویس محدودیت حجم ندارد و نیازی به خرید حجم اضافه نیست.');
        }

        return view('dashboard.services.extra-traffic', [
            'service' => $service,
            'pricePerGb' => $this->addonService->pricePerGb(),
            'minGb' => $this->addonService->minGb(),
            'maxGb' => $this->addonService->maxGb(),
        ]);
    }

    public function submitTraffic(Request $request, UserService $service): RedirectResponse
    {
        $this->authorize('updateOwned', $service);

        $minGb = $this->addonService->minGb();
        $maxGb = $this->addonService->maxGb();

        $validated = $request->validate([
            'amount_gb' => ['required', 'integer', "min:{$minGb}", "max:{$maxGb}"],
        ], [
            'amount_gb.required' => 'مقدار حجم اضافه معتبر نیست.',
            'amount_gb.integer' => 'مقدار حجم اضافه معتبر نیست.',
            'amount_gb.min' => "حداقل حجم قابل خرید {$minGb} گیگابایت است.",
            'amount_gb.max' => "حداکثر حجم قابل خرید {$maxGb} گیگابایت است.",
        ]);

        $user = auth()->user();
        $gb = (int) $validated['amount_gb'];

        try {
            $result = $this->idempotency->createOrReturn(
                $user,
                PurchaseIntent::OP_EXTRA_TRAFFIC,
                ['user_service_id' => $service->id, 'options' => ['amount_gb' => $gb]],
                $request->input('purchase_token'),
                fn (string $fp): Order => $this->addonService->createExtraTrafficOrder($service, $gb, $user, $fp),
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->back($service, $e->getMessage());
        }

        return redirect()
            ->route('dashboard.orders.show', $result['order'])
            ->with($result['reused'] ? 'info' : 'success', $result['message'] ?? '');
    }

    // ── Extra time ───────────────────────────────────────────────────────────

    public function showTime(UserService $service): View|RedirectResponse
    {
        $this->authorize('viewOwned', $service);

        if (! $this->addonService->timeEnabled()) {
            return $this->back($service, 'خرید زمان اضافه در حال حاضر غیرفعال است.');
        }

        if ($this->addonService->pricePerDay() === null) {
            return $this->back($service, 'قیمت هر روز زمان اضافه تنظیم نشده است.');
        }

        if ($service->expires_at === null) {
            return $this->back($service, 'این سرویس تاریخ انقضا ندارد و نیازی به خرید زمان اضافه نیست.');
        }

        return view('dashboard.services.extra-time', [
            'service' => $service,
            'pricePerDay' => $this->addonService->pricePerDay(),
            'minDays' => $this->addonService->minDays(),
            'maxDays' => $this->addonService->maxDays(),
        ]);
    }

    public function submitTime(Request $request, UserService $service): RedirectResponse
    {
        $this->authorize('updateOwned', $service);

        $minDays = $this->addonService->minDays();
        $maxDays = $this->addonService->maxDays();

        $validated = $request->validate([
            'amount_days' => ['required', 'integer', "min:{$minDays}", "max:{$maxDays}"],
        ], [
            'amount_days.required' => 'مقدار زمان اضافه معتبر نیست.',
            'amount_days.integer' => 'مقدار زمان اضافه معتبر نیست.',
            'amount_days.min' => "حداقل زمان قابل خرید {$minDays} روز است.",
            'amount_days.max' => "حداکثر زمان قابل خرید {$maxDays} روز است.",
        ]);

        $user = auth()->user();
        $days = (int) $validated['amount_days'];

        try {
            $result = $this->idempotency->createOrReturn(
                $user,
                PurchaseIntent::OP_EXTRA_TIME,
                ['user_service_id' => $service->id, 'options' => ['amount_days' => $days]],
                $request->input('purchase_token'),
                fn (string $fp): Order => $this->addonService->createExtraTimeOrder($service, $days, $user, $fp),
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->back($service, $e->getMessage());
        }

        return redirect()
            ->route('dashboard.orders.show', $result['order'])
            ->with($result['reused'] ? 'info' : 'success', $result['message'] ?? '');
    }

    private function back(UserService $service, string $message): RedirectResponse
    {
        return redirect()
            ->route('dashboard.services.show', $service)
            ->with('error', $message);
    }
}
