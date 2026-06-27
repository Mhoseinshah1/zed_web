<?php

namespace App\Http\Controllers;

use App\Models\Order;

class OrderController extends Controller
{
    public function index()
    {
        $orders = auth()->user()->orders()->latest()->paginate(15);
        return view('dashboard.orders.index', compact('orders'));
    }

    public function show(Order $order)
    {
        abort_if($order->user_id !== auth()->id(), 403);
        return view('dashboard.orders.show', compact('order'));
    }
}
