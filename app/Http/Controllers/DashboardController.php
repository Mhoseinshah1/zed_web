<?php

namespace App\Http\Controllers;

use App\Models\PaymentTransaction;

class DashboardController extends Controller
{
    public function index()
    {
        $user            = auth()->user();
        $orders          = $user->orders()->latest()->limit(5)->get();
        $pendingPayments = $user->paymentTransactions()
            ->whereIn('status', [PaymentTransaction::STATUS_PENDING, PaymentTransaction::STATUS_SUBMITTED])
            ->count();

        return view('dashboard.index', compact('user', 'orders', 'pendingPayments'));
    }
}
