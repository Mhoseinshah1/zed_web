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
    /** Allowed attachment rules (shared by create + reply). */
    private const ATTACHMENT_RULES = ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf,txt', 'max:5120'];

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
            'attachment'      => self::ATTACHMENT_RULES,
        ], [
            'subject.required' => 'موضوع تیکت الزامی است.',
            'body.required'    => 'متن پیام الزامی است.',
        ]);

        $ticket = $this->tickets->createTicket(
            auth()->user(),
            $validated,
            $request->file('attachment'),
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
            'body'       => ['required', 'string', 'max:5000'],
            'attachment' => self::ATTACHMENT_RULES,
        ], [
            'body.required' => 'متن پیام الزامی است.',
        ]);

        try {
            $this->tickets->userReply($ticket, auth()->user(), $validated['body'], $request->file('attachment'));
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
