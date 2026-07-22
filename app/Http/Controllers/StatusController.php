<?php

namespace App\Http\Controllers;

use App\Services\Seo\SeoManager;
use Illuminate\View\View;

class StatusController extends Controller
{
    public function index(SeoManager $seo): View
    {
        $seo->forKey('status')->breadcrumbs([
            ['name' => 'خانه', 'url' => route('home')],
            ['name' => 'وضعیت سرویس‌ها', 'url' => route('status')],
        ]);

        return view('status');
    }
}
