<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\SiteSetting;
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

        if (! SiteSetting::get('renewal_enabled', true)) {
            return redirect()
                ->route('dashboard.services.show', $service)
                ->with('error', 'تمدید سرویس در حال حاضر غیرفعال است.');
        }

        if ($service->expires_at === null) {
            return redirect()
                ->route('dashboard.services.show', $service)
                ->with('error', 'این سرویس تاریخ انقضا ندارد و قابل تمدید نیست.');
        }

        $plans = Plan::where('is_active', true)
            ->where('renewal_enabled', true)
            ->ordered()
            ->get();

        if ($plans->isEmpty()) {
            return redirect()
                ->route('dashboard.services.show', $service)
                ->with('error', 'در حال حاضر پلنی برای تمدید سرویس موجود نیست. لطفاً با پشتیبانی تماس بگیرید.');
        }

        return view('dashboard.services.renew', compact('service', 'plans'));
    }

    public function submit(Request $request, UserService $service): RedirectResponse
    {
        abort_if($service->user_id !== auth()->id(), 403);

        if (! SiteSetting::get('renewal_enabled', true)) {
            return redirect()
                ->route('dashboard.services.show', $service)
                ->with('error', 'تمدید سرویس در حال حاضر غیرفعال است.');
        }

        if ($service->expires_at === null) {
            return redirect()
                ->route('dashboard.services.show', $service)
                ->with('error', 'این سرویس تاریخ انقضا ندارد و قابل تمدید نیست.');
        }

        $validated = $request->validate([
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
        ]);

        $plan = Plan::where('id', $validated['plan_id'])
            ->where('is_active', true)
            ->where('renewal_enabled', true)
            ->firstOrFail();

        try {
            $order = $this->renewalService->createRenewalOrder($service, $plan, auth()->user());
        } catch (\InvalidArgumentException $e) {
            return redirect()
                ->route('dashboard.services.show', $service)
                ->with('error', $e->getMessage());
        }

        // Land on the order page so the user can apply a discount code before paying.
        return redirect()->route('dashboard.orders.show', $order);
    }
}
