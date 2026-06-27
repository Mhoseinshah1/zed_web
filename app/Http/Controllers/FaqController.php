<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class FaqController extends Controller
{
    public function index(): View
    {
        return view('faq');
    }
}
