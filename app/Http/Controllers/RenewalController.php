<?php

namespace App\Http\Controllers;

use App\Models\RenewalPackage;
use App\Models\UserService;
use App\Services\Renewals\RenewalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RenewalController extends Controller
{
    public function __construct(
        private readonly RenewalService $renewalService,
    ) {}

    public function show(UserService $service): View|RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        if ($service->expires_at === null) {
            return redirect()
                ->route('dashboard.services.show', $service)
                ->with('error', 'این سرویس تاریخ انقضا ندارد و قابل تمدید نیست.');
        }

        $allPackages = RenewalPackage::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('duration_days')
            ->get();

        // Filter to packages allowed for this service's plan
        $packages = $allPackages->filter(fn ($pkg) => $pkg->isAllowedForPlan($service->plan_id));

        if ($packages->isEmpty()) {
            return redirect()
                ->route('dashboard.services.show', $service)
                ->with('error', 'در حال حاضر بسته تمدیدی برای این سرویس موجود نیست. لطفاً با پشتیبانی تماس بگیرید.');
        }

        return view('dashboard.services.renew', compact('service', 'packages'));
    }

    public function submit(Request $request, UserService $service): RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        if ($service->expires_at === null) {
            return redirect()
                ->route('dashboard.services.show', $service)
                ->with('error', 'این سرویس تاریخ انقضا ندارد و قابل تمدید نیست.');
        }

        $validated = $request->validate([
            'renewal_package_id' => ['required', 'integer', 'exists:renewal_packages,id'],
        ]);

        $package = RenewalPackage::where('id', $validated['renewal_package_id'])
            ->where('is_active', true)
            ->firstOrFail();

        try {
            $order = $this->renewalService->createRenewalOrder($service, $package);
        } catch (\InvalidArgumentException $e) {
            return redirect()
                ->route('dashboard.services.show', $service)
                ->with('error', $e->getMessage());
        }

        return redirect()->route('dashboard.orders.pay', $order);
    }
}
