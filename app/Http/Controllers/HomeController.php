<?php

namespace App\Http\Controllers;

use App\Models\Feature;
use App\Models\Location;
use App\Models\Plan;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        $plans     = Plan::active()->ordered()->with('features')->get();
        $features  = Feature::active()->ordered()->get();
        $locations = Location::active()->ordered()->get();

        return view('home', compact('plans', 'features', 'locations'));
    }
}
