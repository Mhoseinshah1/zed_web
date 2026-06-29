<?php

namespace App\Http\Controllers;

use App\Models\UserService;
use App\Services\Addons\ServiceAddonService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ServiceAddonController extends Controller
{
    public function __construct(
        private readonly ServiceAddonService $addonService,
    ) {}

    // ── Extra traffic ────────────────────────────────────────────────────────

    public function showTraffic(UserService $service): View|RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

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
            'service'    => $service,
            'pricePerGb' => $this->addonService->pricePerGb(),
            'minGb'      => $this->addonService->minGb(),
            'maxGb'      => $this->addonService->maxGb(),
        ]);
    }

    public function submitTraffic(Request $request, UserService $service): RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        $minGb = $this->addonService->minGb();
        $maxGb = $this->addonService->maxGb();

        $validated = $request->validate([
            'amount_gb' => ['required', 'integer', "min:{$minGb}", "max:{$maxGb}"],
        ], [
            'amount_gb.required' => 'مقدار حجم اضافه معتبر نیست.',
            'amount_gb.integer'  => 'مقدار حجم اضافه معتبر نیست.',
            'amount_gb.min'      => "حداقل حجم قابل خرید {$minGb} گیگابایت است.",
            'amount_gb.max'      => "حداکثر حجم قابل خرید {$maxGb} گیگابایت است.",
        ]);

        try {
            $order = $this->addonService->createExtraTrafficOrder(
                $service,
                (int) $validated['amount_gb'],
                auth()->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->back($service, $e->getMessage());
        }

        return redirect()->route('dashboard.orders.pay', $order);
    }

    // ── Extra time ───────────────────────────────────────────────────────────

    public function showTime(UserService $service): View|RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

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
            'service'     => $service,
            'pricePerDay' => $this->addonService->pricePerDay(),
            'minDays'     => $this->addonService->minDays(),
            'maxDays'     => $this->addonService->maxDays(),
        ]);
    }

    public function submitTime(Request $request, UserService $service): RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        $minDays = $this->addonService->minDays();
        $maxDays = $this->addonService->maxDays();

        $validated = $request->validate([
            'amount_days' => ['required', 'integer', "min:{$minDays}", "max:{$maxDays}"],
        ], [
            'amount_days.required' => 'مقدار زمان اضافه معتبر نیست.',
            'amount_days.integer'  => 'مقدار زمان اضافه معتبر نیست.',
            'amount_days.min'      => "حداقل زمان قابل خرید {$minDays} روز است.",
            'amount_days.max'      => "حداکثر زمان قابل خرید {$maxDays} روز است.",
        ]);

        try {
            $order = $this->addonService->createExtraTimeOrder(
                $service,
                (int) $validated['amount_days'],
                auth()->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->back($service, $e->getMessage());
        }

        return redirect()->route('dashboard.orders.pay', $order);
    }

    private function back(UserService $service, string $message): RedirectResponse
    {
        return redirect()
            ->route('dashboard.services.show', $service)
            ->with('error', $message);
    }
}
