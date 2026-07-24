<?php

namespace App\Http\Controllers;

use App\Models\Tutorial;
use App\Services\Seo\SeoManager;
use Illuminate\View\View;

class TutorialsController extends Controller
{
    public function index(SeoManager $seo): View
    {
        $tutorials = Tutorial::active()->ordered()->get();
        $grouped = $tutorials->groupBy('platform');

        $seo->forKey('tutorials')->breadcrumbs([
            ['name' => 'خانه', 'url' => route('home')],
            ['name' => 'آموزش‌ها', 'url' => route('tutorials')],
        ]);

        return view('tutorials', compact('tutorials', 'grouped'));
    }

    public function show(string $slug, SeoManager $seo): View
    {
        $tutorial = Tutorial::where('slug', $slug)->where('is_active', true)->firstOrFail();
        $related = Tutorial::active()->ordered()
            ->where('id', '!=', $tutorial->id)
            ->where('platform', $tutorial->platform)
            ->limit(4)->get();

        // Detail-page SEO is model-driven (Article/TechArticle schema, canonical,
        // published_at). No page_key: the tutorials key is the index collection.
        $seo->useModel($tutorial)->breadcrumbs([
            ['name' => 'خانه', 'url' => route('home')],
            ['name' => 'آموزش‌ها', 'url' => route('tutorials')],
            ['name' => $tutorial->title, 'url' => route('tutorials.show', $tutorial->slug)],
        ]);

        return view('tutorials-show', compact('tutorial', 'related'));
    }
}
