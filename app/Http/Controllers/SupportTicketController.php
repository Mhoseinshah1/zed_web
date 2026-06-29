<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket;
use App\Models\SupportTicketCategory;
use App\Services\Support\SupportTicketService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupportTicketController extends Controller
{
    /** Whitelisted attachment types — executables (php/js/html/exe/sh/…) are rejected. */
    private const ATTACHMENT_RULES = ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf,txt', 'max:5120'];

    private const ATTACHMENT_MESSAGES = [
        'attachments.*.mimes' => 'فرمت فایل پیوست مجاز نیست. فقط jpg، png، webp، pdf و txt پذیرفته می‌شود.',
        'attachments.*.max'   => 'حجم هر فایل پیوست نباید بیشتر از ۵ مگابایت باشد.',
    ];

    public function __construct(
        private readonly SupportTicketService $tickets,
    ) {}

    public function index(): View
    {
        $tickets = SupportTicket::forUser(auth()->id())
            ->with('category')
            ->latest('last_reply_at')
            ->latest()
            ->paginate(15);

        return view('dashboard.tickets.index', compact('tickets'));
    }

    public function create(): View
    {
        $user = auth()->user();

        return view('dashboard.tickets.create', [
            'categories' => SupportTicketCategory::active()->ordered()->get(),
            'priorities' => SupportTicket::priorities(),
            'orders'     => $user->orders()->latest()->limit(50)->get(),
            'services'   => $user->services()->latest()->limit(50)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'subject'         => ['required', 'string', 'max:255'],
            'body'            => ['required', 'string', 'max:5000'],
            'category_id'     => ['nullable', 'integer', 'exists:support_ticket_categories,id'],
            'priority'        => ['nullable', 'string'],
            'order_id'        => ['nullable', 'integer'],
            'user_service_id' => ['nullable', 'integer'],
            'attachments'     => ['nullable', 'array', 'max:5'],
            'attachments.*'   => self::ATTACHMENT_RULES,
        ], array_merge([
            'subject.required' => 'موضوع تیکت الزامی است.',
            'body.required'    => 'متن پیام الزامی است.',
        ], self::ATTACHMENT_MESSAGES));

        $ticket = $this->tickets->createTicket(
            auth()->user(),
            $validated,
            $request->file('attachments', []),
        );

        return redirect()
            ->route('dashboard.tickets.show', $ticket)
            ->with('success', 'تیکت شما با موفقیت ثبت شد. شماره تیکت: ' . $ticket->ticket_number);
    }

    public function show(SupportTicket $ticket): View
    {
        abort_if($ticket->user_id !== auth()->id(), 403);

        // Owner is viewing — clear their unread flag and load only public messages.
        $this->tickets->markReadByUser($ticket);

        $ticket->load(['category', 'order', 'userService', 'publicMessages.user']);

        return view('dashboard.tickets.show', compact('ticket'));
    }

    public function reply(Request $request, SupportTicket $ticket): RedirectResponse
    {
        abort_if($ticket->user_id !== auth()->id(), 403);

        $validated = $request->validate([
            'body'          => ['required', 'string', 'max:5000'],
            'attachments'   => ['nullable', 'array', 'max:5'],
            'attachments.*' => self::ATTACHMENT_RULES,
        ], array_merge([
            'body.required' => 'متن پیام الزامی است.',
        ], self::ATTACHMENT_MESSAGES));

        try {
            $this->tickets->userReply($ticket, auth()->user(), $validated['body'], $request->file('attachments', []));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('dashboard.tickets.show', $ticket)->with('success', 'پاسخ شما ثبت شد.');
    }

    public function close(SupportTicket $ticket): RedirectResponse
    {
        abort_if($ticket->user_id !== auth()->id(), 403);

        $this->tickets->close($ticket);

        return back()->with('success', 'تیکت بسته شد.');
    }
}
