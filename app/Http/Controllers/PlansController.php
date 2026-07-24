<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\Plan;
use App\Models\PlanCategory;
use App\Services\Seo\SeoManager;
use Illuminate\View\View;

class PlansController extends Controller
{
    public function index(SeoManager $seo): View
    {
        $plans = Plan::active()->ordered()->with(['features', 'category'])->get();
        $categories = PlanCategory::active()->ordered()
            ->whereHas('plans', fn ($q) => $q->where('is_active', true))
            ->get();
        $topBanners = Banner::forPlacement('shop_top')->merge(Banner::forPlacement('plans_top'));

        // Plans SEO — ItemList schema from the already-loaded plans (no re-query);
        // canonical drops the ?category filter so filters never duplicate pages.
        $seo->forKey('plans')
            ->set(['plans' => $plans])
            ->breadcrumbs([
                ['name' => 'خانه', 'url' => route('home')],
                ['name' => 'پلن‌ها', 'url' => route('plans')],
            ]);

        return view('plans', compact('plans', 'categories', 'topBanners'));
    }
}
