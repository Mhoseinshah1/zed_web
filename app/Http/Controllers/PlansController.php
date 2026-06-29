<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\Plan;
use App\Models\PlanCategory;
use Illuminate\View\View;

class PlansController extends Controller
{
    public function index(): View
    {
        $plans      = Plan::active()->ordered()->with(['features', 'category'])->get();
        $categories = PlanCategory::active()->ordered()
            ->whereHas('plans', fn ($q) => $q->where('is_active', true))
            ->get();
        $topBanners = Banner::forPlacement('shop_top')->merge(Banner::forPlacement('plans_top'));

        return view('plans', compact('plans', 'categories', 'topBanners'));
    }
}
