<?php

namespace App\Http\Controllers;

class DashboardController extends Controller
{
    public function index()
    {
        $user   = auth()->user();
        $orders = $user->orders()->latest()->limit(5)->get();

        return view('dashboard.index', compact('user', 'orders'));
    }
}
