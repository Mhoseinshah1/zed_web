<?php

namespace App\Http\Controllers;

class WalletController extends Controller
{
    public function index()
    {
        $user         = auth()->user();
        $transactions = $user->walletTransactions()->latest()->paginate(20);

        return view('dashboard.wallet', compact('user', 'transactions'));
    }
}
