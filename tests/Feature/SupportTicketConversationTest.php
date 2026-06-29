<?php

namespace Tests\Feature;

use App\Livewire\AdminTicketReplyComposer;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Services\Support\SupportTicketService;
use App\Support\MessageFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SupportTicketConversationTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(User $user): SupportTicket
    {
        return SupportTicket::create([
            'user_id'  => $user->id,
            'subject'  => 'موضوع',
            'status'   => SupportTicket::STATUS_WAITING_ADMIN,
            'priority' => SupportTicket::PRIORITY_NORMAL,
        ]);
    }

    private function message(SupportTicket $ticket, array $overrides = []): SupportTicketMessage
    {
        return SupportTicketMessage::create(array_merge([
            'support_ticket_id' => $ticket->id,
            'user_id'           => $ticket->user_id,
            'body'              => 'سلام',
        ], $overrides));
    }

    // ── Attachment preview ───────────────────────────────────────────────────

    public function test_image_attachment_renders_preview(): void
    {
        Storage::fake('public');
        $user   = User::factory()->create();
        $ticket = $this->ticket($user);
        $msg    = $this->message($ticket);
        // Place a real file so exists() is true.
        Storage::disk('public')->put('support-tickets/pic.png', 'x');
        $msg->attachments()->create(['path' => 'support-tickets/pic.png', 'original_name' => 'pic.png']);

        $this->actingAs($user)
            ->get(route('dashboard.tickets.show', $ticket))
            ->assertStatus(200)
            ->assertSee('pic.png')
            ->assertSee('<img', false); // thumbnail rendered
    }

    public function test_non_image_attachment_renders_download_link(): void
    {
        Storage::fake('public');
        $user   = User::factory()->create();
        $ticket = $this->ticket($user);
        $msg    = $this->message($ticket);
        Storage::disk('public')->put('support-tickets/doc.pdf', 'x');
        $msg->attachments()->create(['path' => 'support-tickets/doc.pdf', 'original_name' => 'doc.pdf']);

        $this->actingAs($user)
            ->get(route('dashboard.tickets.show', $ticket))
            ->assertStatus(200)
            ->assertSee('doc.pdf')
            ->assertSee('PDF');
    }

    public function test_missing_attachment_does_not_crash_page(): void
    {
        Storage::fake('public');
        $user   = User::factory()->create();
        $ticket = $this->ticket($user);
        $msg    = $this->message($ticket);
        // Reference a file that does not exist on disk.
        $msg->attachments()->create(['path' => 'support-tickets/missing.png', 'original_name' => 'missing.png']);

        $this->actingAs($user)
            ->get(route('dashboard.tickets.show', $ticket))
            ->assertStatus(200)
            ->assertSee('فایل پیوست در دسترس نیست.');
    }

    public function test_multiple_attachments_supported_per_message(): void
    {
        Storage::fake('public');
        $user   = User::factory()->create();
        $ticket = $this->ticket($user);

        $response = $this->actingAs($user)->post(route('dashboard.tickets.reply', $ticket), [
            'body'        => 'با دو فایل',
            'attachments' => [
                UploadedFile::fake()->image('a.png'),
                UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
            ],
        ]);

        $response->assertRedirect();
        $msg = $ticket->messages()->where('is_admin', false)->latest('id')->first();
        $this->assertSame(2, $msg->attachments()->count());
    }

    // ── Link handling / XSS ──────────────────────────────────────────────────

    public function test_links_are_clickable(): void
    {
        $html = (string) MessageFormatter::linkify('see https://example.com now');
        $this->assertStringContainsString('<a href="https://example.com"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener nofollow"', $html);
    }

    public function test_unsafe_html_is_escaped(): void
    {
        $html = (string) MessageFormatter::linkify('<script>alert(1)</script>');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_message_body_is_escaped_in_view(): void
    {
        $user   = User::factory()->create();
        $ticket = $this->ticket($user);
        $this->message($ticket, ['body' => '<b>xss</b> <script>alert(1)</script>']);

        $this->actingAs($user)
            ->get(route('dashboard.tickets.show', $ticket))
            ->assertStatus(200)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    // ── Inline admin reply (Filament Livewire page) ──────────────────────────

    public function test_admin_can_reply_from_inline_form(): void
    {
        $admin  = User::factory()->create(['is_admin' => true]);
        $user   = User::factory()->create();
        $ticket = $this->ticket($user);

        Livewire::actingAs($admin)
            ->test(AdminTicketReplyComposer::class, ['ticketId' => $ticket->id])
            ->set('data.body', 'پاسخ از فرم درون‌خطی')
            ->set('data.is_internal_note', false)
            ->call('send')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('support_ticket_messages', [
            'support_ticket_id' => $ticket->id,
            'is_admin'          => true,
            'is_internal_note'  => false,
            'body'              => 'پاسخ از فرم درون‌خطی',
        ]);

        // Status moved to answered + user notified.
        $this->assertSame(SupportTicket::STATUS_ANSWERED, $ticket->fresh()->status);
        $this->assertTrue($ticket->fresh()->user_unread);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type'    => \App\Models\Notification::TYPE_TICKET_ADMIN_REPLY,
        ]);
    }

    public function test_admin_inline_internal_note_hidden_from_user(): void
    {
        $admin  = User::factory()->create(['is_admin' => true]);
        $user   = User::factory()->create();
        $ticket = $this->ticket($user);

        Livewire::actingAs($admin)
            ->test(AdminTicketReplyComposer::class, ['ticketId' => $ticket->id])
            ->set('data.body', 'یادداشت محرمانه درون‌خطی')
            ->set('data.is_internal_note', true)
            ->call('send')
            ->assertHasNoErrors();

        // Not shown to user, status not bumped to answered.
        $this->assertNotSame(SupportTicket::STATUS_ANSWERED, $ticket->fresh()->status);
        $this->actingAs($user)
            ->get(route('dashboard.tickets.show', $ticket))
            ->assertDontSee('یادداشت محرمانه درون‌خطی');
    }

    public function test_user_sees_admin_inline_reply(): void
    {
        $admin  = User::factory()->create(['is_admin' => true]);
        $user   = User::factory()->create();
        $ticket = $this->ticket($user);

        app(SupportTicketService::class)->adminReply($ticket, $admin, 'پاسخ قابل مشاهده', internal: false);

        $this->actingAs($user)
            ->get(route('dashboard.tickets.show', $ticket))
            ->assertSee('پاسخ قابل مشاهده');
    }

    // ── Attachment validation / security ─────────────────────────────────────

    public function test_attachment_validation_rejects_unsafe_file_types(): void
    {
        $user   = User::factory()->create();
        $ticket = $this->ticket($user);

        $this->actingAs($user)
            ->post(route('dashboard.tickets.reply', $ticket), [
                'body'        => 'با فایل خطرناک',
                'attachments' => [UploadedFile::fake()->create('evil.php', 10, 'application/x-php')],
            ])
            ->assertSessionHasErrors('attachments.0');

        $this->assertDatabaseMissing('support_ticket_messages', ['body' => 'با فایل خطرناک']);
    }
}
