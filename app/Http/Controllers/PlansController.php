<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class PlansController extends Controller
{
    public function index(): View
    {
        return view('plans');
    }
}
