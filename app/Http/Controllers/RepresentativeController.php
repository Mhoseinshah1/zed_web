<?php

namespace App\Http\Controllers;

use App\Models\Commission;
use App\Models\Order;
use App\Models\User;
use App\Services\Referrals\ReferralSettings;
use App\Services\Referrals\RepresentativeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RepresentativeController extends Controller
{
    public function __construct(
        private readonly RepresentativeService $representatives,
    ) {}

    public function index(): View
    {
        $user = auth()->user();
        $mode = ReferralSettings::mode();

        // Who may see their referral code/link?
        $canInvite = $mode === ReferralSettings::MODE_ALL_USERS || $user->isApprovedRepresentative();

        $referredUsers = $user->referredUsers()->latest()->limit(10)->get();
        $referredIds   = $user->referredUsers()->pluck('id');

        $recentOrders = Order::whereIn('user_id', $referredIds)
            ->where('payment_status', Order::PAYMENT_PAID)
            ->latest('paid_at')
            ->limit(10)
            ->with('user')
            ->get();

        $commissions = $user->commissionsAsRepresentative()->latest()->limit(20)->get();

        return view('dashboard.representative.index', [
            'user'              => $user,
            'mode'              => $mode,
            'canInvite'         => $canInvite,
            'systemEnabled'     => ReferralSettings::representativeSystemEnabled(),
            'referredCount'     => $user->referredUsers()->count(),
            'paidOrdersCount'   => Order::whereIn('user_id', $referredIds)->where('payment_status', Order::PAYMENT_PAID)->count(),
            'totalCommission'   => (int) $user->total_commission_earned,
            'pendingCommission' => (int) $user->commissionsAsRepresentative()->where('status', Commission::STATUS_PENDING)->sum('commission_amount'),
            'referredUsers'     => $referredUsers,
            'recentOrders'      => $recentOrders,
            'commissions'       => $commissions,
        ]);
    }

    public function requestAccess(Request $request): RedirectResponse
    {
        $user = auth()->user();

        if (! ReferralSettings::representativeSystemEnabled()) {
            return back()->with('error', 'سیستم نمایندگان در حال حاضر غیرفعال است.');
        }

        if ($user->isApprovedRepresentative()) {
            return back()->with('error', 'شما هم‌اکنون نماینده هستید.');
        }

        if ($user->representative_status === User::REP_PENDING) {
            return back()->with('error', 'درخواست نمایندگی شما در حال بررسی است.');
        }

        $validated = $request->validate([
            'message'      => ['nullable', 'string', 'max:1000'],
            'contact_info' => ['nullable', 'string', 'max:255'],
        ]);

        $this->representatives->request($user, $validated['message'] ?? null, $validated['contact_info'] ?? null);

        return back()->with('success', 'درخواست نمایندگی شما ثبت شد.');
    }
}
