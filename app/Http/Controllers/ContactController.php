<?php

namespace App\Http\Controllers;

use App\Services\Seo\SeoManager;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function index(SeoManager $seo): View
    {
        // ContactPage + Organization ContactPoint schema (only if real data set).
        $seo->forKey('contact')->breadcrumbs([
            ['name' => 'خانه', 'url' => route('home')],
            ['name' => 'تماس با ما', 'url' => route('contact')],
        ]);

        return view('contact');
    }
}
