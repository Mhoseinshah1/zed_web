<?php

namespace App\Http\Controllers;

use App\Models\Faq;
use App\Services\Seo\SeoManager;
use Illuminate\View\View;

class FaqController extends Controller
{
    public function index(SeoManager $seo): View
    {
        $faqs = Faq::active()->ordered()->get();

        // FAQPage JSON-LD is built from exactly these active, visible FAQs.
        $seo->forKey('faq')
            ->set(['faqs' => $faqs])
            ->breadcrumbs([
                ['name' => 'خانه', 'url' => route('home')],
                ['name' => 'سوالات متداول', 'url' => route('faq')],
            ]);

        return view('faq', compact('faqs'));
    }
}
