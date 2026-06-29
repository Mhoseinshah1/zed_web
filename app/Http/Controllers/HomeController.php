<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\Faq;
use App\Models\Feature;
use App\Models\LandingSection;
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
        $faqs      = Faq::active()->ordered()->limit(6)->get();
        $sections  = LandingSection::active()->ordered()->get();
        $topBanners    = Banner::forPlacement('home_top')->merge(Banner::forPlacement('homepage_top'));
        $middleBanners = Banner::forPlacement('home_middle')->merge(Banner::forPlacement('homepage_middle'));

        return view('home', compact(
            'plans', 'features', 'locations', 'faqs', 'sections', 'topBanners', 'middleBanners'
        ));
    }
}
