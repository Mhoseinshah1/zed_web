<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(): View
    {
        $userId = auth()->id();

        $notifications = Notification::forUser($userId)
            ->latest()
            ->paginate(20);

        $unreadCount = Notification::forUser($userId)->unread()->count();

        return view('dashboard.notifications.index', compact('notifications', 'unreadCount'));
    }

    public function markRead(Notification $notification): RedirectResponse
    {
        abort_if($notification->user_id !== auth()->id(), 403);

        $notification->markRead();

        return back()->with('success', 'اعلان خوانده شد.');
    }

    public function markAllRead(): RedirectResponse
    {
        Notification::forUser(auth()->id())
            ->unread()
            ->update(['read_at' => now()]);

        return back()->with('success', 'همه اعلان‌ها خوانده شدند.');
    }
}
