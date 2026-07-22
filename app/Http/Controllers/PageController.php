<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Services\Seo\SeoManager;
use Illuminate\View\View;

class PageController extends Controller
{
    public function show(string $slug, SeoManager $seo): View
    {
        $page = Page::where('slug', $slug)->where('is_active', true)->firstOrFail();

        // Merge any matching SeoPage record (terms/privacy/about) over the CMS
        // page fallbacks (title→excerpt→default image chain lives in SeoManager).
        $seo->forKey($page->slug)->useModel($page)->breadcrumbs([
            ['name' => 'خانه', 'url' => route('home')],
            ['name' => $page->title, 'url' => route('pages.show', $page->slug)],
        ]);

        return view('pages.show', compact('page'));
    }
}
