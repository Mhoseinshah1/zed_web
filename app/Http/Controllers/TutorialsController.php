<?php

namespace App\Http\Controllers;

use App\Models\Tutorial;
use Illuminate\View\View;

class TutorialsController extends Controller
{
    public function index(): View
    {
        $tutorials = Tutorial::active()->ordered()->get();
        $grouped   = $tutorials->groupBy('platform');

        return view('tutorials', compact('tutorials', 'grouped'));
    }

    public function show(string $slug): View
    {
        $tutorial = Tutorial::where('slug', $slug)->where('is_active', true)->firstOrFail();
        $related  = Tutorial::active()->ordered()
            ->where('id', '!=', $tutorial->id)
            ->where('platform', $tutorial->platform)
            ->limit(4)->get();

        return view('tutorials-show', compact('tutorial', 'related'));
    }
}
