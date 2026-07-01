<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Models\SupportTicket;
use App\Models\User;
use Filament\Widgets\Widget;

/**
 * «فعالیت اخیر» — a compact, merged activity feed (recent paid orders, new users,
 * new tickets). Each source is a small LIMITed query with the needed columns
 * only, so this stays cheap (no full scans, no N+1). Display-only.
 */
class RecentActivityWidget extends Widget
{
    protected static string $view = 'filament.widgets.recent-activity';

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = ['default' => 'full', 'lg' => 2];

    /** @return array{items: array<int,array<string,mixed>>} */
    protected function getViewData(): array
    {
        $items = collect();

        Order::query()
            ->where('payment_status', Order::PAYMENT_PAID)
            ->whereNotNull('paid_at')
            ->with('user:id,username,account_id')
            ->latest('paid_at')
            ->limit(4)
            ->get(['id', 'order_number', 'user_id', 'final_price_toman', 'paid_at'])
            ->each(fn (Order $o) => $items->push([
                'icon'  => 'heroicon-o-check-circle',
                'color' => 'success',
                'title' => 'پرداخت موفق',
                'meta'  => 'سفارش ' . $o->order_number . ' — ' . number_format((int) $o->final_price_toman) . ' تومان',
                'time'  => $o->paid_at,
            ]));

        User::query()
            ->latest()
            ->limit(4)
            ->get(['id', 'name', 'username', 'created_at'])
            ->each(fn (User $u) => $items->push([
                'icon'  => 'heroicon-o-user-plus',
                'color' => 'primary',
                'title' => 'کاربر جدید',
                'meta'  => ($u->name ?: $u->username) . ' ثبت‌نام کرد',
                'time'  => $u->created_at,
            ]));

        SupportTicket::query()
            ->latest()
            ->limit(4)
            ->get(['id', 'ticket_number', 'subject', 'created_at'])
            ->each(fn (SupportTicket $t) => $items->push([
                'icon'  => 'heroicon-o-chat-bubble-left-right',
                'color' => 'info',
                'title' => 'تیکت جدید',
                'meta'  => $t->subject ?: $t->ticket_number,
                'time'  => $t->created_at,
            ]));

        $items = $items
            ->filter(fn ($i) => $i['time'] !== null)
            ->sortByDesc(fn ($i) => $i['time']->getTimestamp())
            ->take(6)
            ->map(function ($i) {
                $i['ago'] = $i['time']->locale('fa')->diffForHumans();

                return $i;
            })
            ->values()
            ->all();

        return ['items' => $items];
    }
}
