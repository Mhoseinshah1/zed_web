<?php

namespace App\Http\Controllers;

use App\Models\PaymentTransaction;
use App\Models\UserService;

class DashboardController extends Controller
{
    public function index()
    {
        $user            = auth()->user();
        $orders          = $user->orders()->latest()->limit(5)->get();
        $latestServices  = $user->services()->latest()->limit(3)->get();
        $activeServices  = $user->services()->where('status', UserService::STATUS_ACTIVE)->count();
        $pendingServices = $user->services()->where('status', UserService::STATUS_PENDING_PROVISION)->count();
        $pendingPayments = $user->paymentTransactions()
            ->whereIn('status', [PaymentTransaction::STATUS_PENDING, PaymentTransaction::STATUS_SUBMITTED])
            ->count();

        return view('dashboard.index', compact(
            'user',
            'orders',
            'latestServices',
            'activeServices',
            'pendingServices',
            'pendingPayments',
        ));
    }
}
