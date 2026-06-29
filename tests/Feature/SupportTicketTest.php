<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\SupportTicket;
use App\Models\SupportTicketCategory;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Services\Support\SupportTicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportTicketTest extends TestCase
{
    use RefreshDatabase;

    private function category(): SupportTicketCategory
    {
        return SupportTicketCategory::firstOrCreate(['name' => 'مشکل خرید'], ['is_active' => true]);
    }

    private function makeTicket(User $user, array $overrides = []): SupportTicket
    {
        return SupportTicket::create(array_merge([
            'user_id'  => $user->id,
            'subject'  => 'موضوع تست',
            'status'   => SupportTicket::STATUS_WAITING_ADMIN,
            'priority' => SupportTicket::PRIORITY_NORMAL,
        ], $overrides));
    }

    // ── Ticket number format ─────────────────────────────────────────────────

    public function test_ticket_number_is_generated_automatically(): void
    {
        $ticket = $this->makeTicket(User::factory()->create());
        $this->assertNotEmpty($ticket->ticket_number);
    }

    public function test_ticket_number_is_exactly_10_digits(): void
    {
        $ticket = $this->makeTicket(User::factory()->create());
        $this->assertSame(10, strlen($ticket->ticket_number));
    }

    public function test_ticket_number_is_numeric_only_with_no_prefix(): void
    {
        $ticket = $this->makeTicket(User::factory()->create());
        $this->assertTrue(ctype_digit($ticket->ticket_number));
        $this->assertStringNotContainsString('TKT', $ticket->ticket_number);
        $this->assertStringNotContainsString('-', $ticket->ticket_number);
    }

    public function test_ticket_number_is_unique(): void
    {
        $user = User::factory()->create();
        $numbers = collect(range(1, 25))->map(fn () => $this->makeTicket($user)->ticket_number);
        $this->assertSame($numbers->count(), $numbers->unique()->count());
    }

    public function test_ticket_number_does_not_change_after_creation(): void
    {
        $ticket = $this->makeTicket(User::factory()->create());
        $original = $ticket->ticket_number;
        $ticket->update(['subject' => 'changed']);
        $this->assertSame($original, $ticket->fresh()->ticket_number);
    }

    // ── User flow ────────────────────────────────────────────────────────────

    public function test_user_can_create_ticket(): void
    {
        $user = User::factory()->create();
        $cat  = $this->category();

        $response = $this->actingAs($user)->post(route('dashboard.tickets.store'), [
            'subject'     => 'مشکل در پرداخت',
            'body'        => 'پرداخت من انجام نشد.',
            'category_id' => $cat->id,
            'priority'    => 'high',
        ]);

        $ticket = SupportTicket::where('user_id', $user->id)->first();
        $this->assertNotNull($ticket);
        $response->assertRedirect(route('dashboard.tickets.show', $ticket));
        $this->assertDatabaseHas('support_ticket_messages', [
            'support_ticket_id' => $ticket->id,
            'is_admin'          => false,
            'body'              => 'پرداخت من انجام نشد.',
        ]);
    }

    public function test_user_can_view_own_tickets(): void
    {
        $user   = User::factory()->create();
        $ticket = $this->makeTicket($user, ['subject' => 'تیکت خودم']);
        SupportTicketMessage::create(['support_ticket_id' => $ticket->id, 'user_id' => $user->id, 'body' => 'سلام']);

        $this->actingAs($user)->get(route('dashboard.tickets'))->assertStatus(200)->assertSee('تیکت خودم');
        $this->actingAs($user)->get(route('dashboard.tickets.show', $ticket))->assertStatus(200)->assertSee($ticket->ticket_number);
    }

    public function test_user_cannot_view_another_users_ticket(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ticket = $this->makeTicket($owner);

        $this->actingAs($other)->get(route('dashboard.tickets.show', $ticket))->assertStatus(403);
    }

    public function test_user_can_reply_to_own_open_ticket(): void
    {
        $user   = User::factory()->create();
        $ticket = $this->makeTicket($user);

        $this->actingAs($user)
            ->post(route('dashboard.tickets.reply', $ticket), ['body' => 'پاسخ من'])
            ->assertRedirect(route('dashboard.tickets.show', $ticket));

        $this->assertDatabaseHas('support_ticket_messages', [
            'support_ticket_id' => $ticket->id,
            'body'              => 'پاسخ من',
            'is_admin'          => false,
        ]);
    }

    public function test_user_cannot_reply_to_closed_ticket(): void
    {
        $user   = User::factory()->create();
        $ticket = $this->makeTicket($user, ['status' => SupportTicket::STATUS_CLOSED, 'closed_at' => now()]);

        $this->actingAs($user)
            ->post(route('dashboard.tickets.reply', $ticket), ['body' => 'پاسخ بعد از بستن'])
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('support_ticket_messages', ['body' => 'پاسخ بعد از بستن']);
    }

    public function test_user_cannot_reply_to_another_users_ticket(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ticket = $this->makeTicket($owner);

        $this->actingAs($other)
            ->post(route('dashboard.tickets.reply', $ticket), ['body' => 'نفوذ'])
            ->assertStatus(403);
    }

    public function test_user_can_close_own_ticket(): void
    {
        $user   = User::factory()->create();
        $ticket = $this->makeTicket($user);

        $this->actingAs($user)->post(route('dashboard.tickets.close', $ticket))->assertRedirect();
        $this->assertSame(SupportTicket::STATUS_CLOSED, $ticket->fresh()->status);
    }

    // ── Related order/service ownership ──────────────────────────────────────

    public function test_related_order_must_belong_to_user(): void
    {
        $user  = User::factory()->create();
        $other = User::factory()->create();
        $plan  = \App\Models\Plan::create([
            'name' => 'p', 'slug' => 'p-' . uniqid(), 'price_toman' => 1000,
            'duration_days' => 30, 'traffic_gb' => 10, 'is_active' => true, 'sort_order' => 0,
        ]);
        $foreignOrder = Order::create([
            'order_type' => Order::TYPE_NEW_SERVICE, 'user_id' => $other->id, 'plan_id' => $plan->id,
            'plan_name' => 'p', 'price_toman' => 1000, 'final_price_toman' => 1000, 'discount_toman' => 0,
            'status' => Order::STATUS_PAID, 'payment_status' => Order::PAYMENT_PAID,
        ]);

        $ticket = app(SupportTicketService::class)->createTicket($user, [
            'subject' => 's', 'body' => 'b', 'order_id' => $foreignOrder->id,
        ]);

        // Foreign order must not be attached.
        $this->assertNull($ticket->order_id);
    }

    // ── Categories ───────────────────────────────────────────────────────────

    public function test_default_categories_are_seeded(): void
    {
        $this->assertDatabaseHas('support_ticket_categories', ['name' => 'مشکل پرداخت']);
        $this->assertGreaterThanOrEqual(8, SupportTicketCategory::count());
    }

    // ── Admin ────────────────────────────────────────────────────────────────

    public function test_admin_can_search_tickets_by_ticket_number(): void
    {
        $admin  = User::factory()->create(['is_admin' => true]);
        $ticket = $this->makeTicket(User::factory()->create());

        $this->actingAs($admin)
            ->get('/zed-admin/support-tickets?tableSearch=' . $ticket->ticket_number)
            ->assertStatus(200)
            ->assertSee($ticket->ticket_number);
    }

    public function test_admin_can_search_tickets_by_user_account_id(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $owner = User::factory()->create();
        $ticket = $this->makeTicket($owner, ['subject' => 'پیدا کن منو']);

        $this->actingAs($admin)
            ->get('/zed-admin/support-tickets?tableSearch=' . $owner->account_id)
            ->assertStatus(200)
            ->assertSee($ticket->ticket_number);
    }

    public function test_admin_can_reply_and_user_sees_it(): void
    {
        $admin  = User::factory()->create(['is_admin' => true]);
        $user   = User::factory()->create();
        $ticket = $this->makeTicket($user);

        app(SupportTicketService::class)->adminReply($ticket, $admin, 'پاسخ پشتیبانی', internal: false);

        $this->assertSame(SupportTicket::STATUS_ANSWERED, $ticket->fresh()->status);
        $this->assertTrue($ticket->fresh()->user_unread);

        $this->actingAs($user)
            ->get(route('dashboard.tickets.show', $ticket))
            ->assertSee('پاسخ پشتیبانی');
    }

    public function test_internal_notes_are_hidden_from_user(): void
    {
        $admin  = User::factory()->create(['is_admin' => true]);
        $user   = User::factory()->create();
        $ticket = $this->makeTicket($user);

        app(SupportTicketService::class)->adminReply($ticket, $admin, 'یادداشت محرمانه ادمین', internal: true);

        // Internal note does not change status or notify the user.
        $this->assertNotSame(SupportTicket::STATUS_ANSWERED, $ticket->fresh()->status);

        $this->actingAs($user)
            ->get(route('dashboard.tickets.show', $ticket))
            ->assertDontSee('یادداشت محرمانه ادمین');

        // But it exists in the database.
        $this->assertDatabaseHas('support_ticket_messages', [
            'support_ticket_id' => $ticket->id,
            'is_internal_note'  => true,
            'body'              => 'یادداشت محرمانه ادمین',
        ]);
    }

    public function test_admin_reply_notifies_user_when_notifications_enabled(): void
    {
        $admin  = User::factory()->create(['is_admin' => true]);
        $user   = User::factory()->create();
        $ticket = $this->makeTicket($user);

        app(SupportTicketService::class)->adminReply($ticket, $admin, 'پاسخ', internal: false);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type'    => \App\Models\Notification::TYPE_TICKET_ADMIN_REPLY,
        ]);
    }
}
