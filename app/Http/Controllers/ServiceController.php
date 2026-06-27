<?php

namespace App\Http\Controllers;

use App\Models\UserService;

class ServiceController extends Controller
{
    public function index()
    {
        $user     = auth()->user();
        $services = $user->services()->latest()->paginate(15);

        return view('dashboard.services.index', compact('user', 'services'));
    }

    public function show(UserService $service)
    {
        abort_if($service->user_id !== auth()->id(), 403);

        return view('dashboard.services.show', compact('service'));
    }
}
