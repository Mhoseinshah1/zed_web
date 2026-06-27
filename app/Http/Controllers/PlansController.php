<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\View\View;

class PlansController extends Controller
{
    public function index(): View
    {
        $plans = Plan::active()->ordered()->with('features')->get();

        return view('plans', compact('plans'));
    }
}
