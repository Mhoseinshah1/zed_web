<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class TutorialsController extends Controller
{
    public function index(): View
    {
        return view('tutorials');
    }
}
