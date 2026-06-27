<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class StatusController extends Controller
{
    public function index(): View
    {
        return view('status');
    }
}
