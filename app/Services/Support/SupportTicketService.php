<?php

namespace App\Services\Support;

use App\Models\Notification;
use App\Models\Order;
use App\Models\SupportTicket;
use App\Models\SupportTicketAttachment;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Models\UserService;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class SupportTicketService
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Create a ticket with its first message on behalf of a user.
     *
     * Related order/service are only attached when they belong to the user.
     *
     * @param array{subject:string,body:string,category_id?:int|null,priority?:string,order_id?:int|null,user_service_id?:int|null} $data
     * @param array<int, UploadedFile> $attachments
     */
    public function createTicket(User $user, array $data, array $attachments = []): SupportTicket
    {
        $orderId   = $this->ownedOrderId($user, $data['order_id'] ?? null);
        $serviceId = $this->ownedServiceId($user, $data['user_service_id'] ?? null);

        $ticket = DB::transaction(function () use ($user, $data, $orderId, $serviceId, $attachments) {
            $ticket = SupportTicket::create([
                'user_id'         => $user->id,
                'category_id'     => $data['category_id'] ?? null,
                'order_id'        => $orderId,
                'user_service_id' => $serviceId,
                'subject'         => $data['subject'],
                'status'          => SupportTicket::STATUS_WAITING_ADMIN,
                'priority'        => $this->normalizePriority($data['priority'] ?? null),
                'last_reply_at'   => now(),
                'admin_unread'    => true,
                'user_unread'     => false,
            ]);

            $this->storeMessage($ticket, $user, $data['body'], isAdmin: false, isInternal: false, attachments: $attachments);

            return $ticket;
        });

        $this->notifications->notifyAdmins(
            Notification::TYPE_TICKET_CREATED,
            [
                'user_name'     => $user->name ?? $user->username,
                'ticket_number' => $ticket->ticket_number,
                'subject'       => $ticket->subject,
            ],
            'ticket_created:' . $ticket->id,
        );

        return $ticket;
    }

    /**
     * A user replies to their own open ticket.
     *
     * @throws \RuntimeException when the ticket is closed
     */
    /**
     * @param array<int, UploadedFile> $attachments
     */
    public function userReply(SupportTicket $ticket, User $user, string $body, array $attachments = []): SupportTicketMessage
    {
        if ($ticket->isClosed()) {
            throw new \RuntimeException('این تیکت بسته شده است و امکان پاسخ وجود ندارد.');
        }

        $message = DB::transaction(function () use ($ticket, $user, $body, $attachments) {
            $message = $this->storeMessage($ticket, $user, $body, isAdmin: false, isInternal: false, attachments: $attachments);

            $ticket->update([
                'status'        => SupportTicket::STATUS_WAITING_ADMIN,
                'last_reply_at' => now(),
                'admin_unread'  => true,
                'user_unread'   => false,
            ]);

            return $message;
        });

        $this->notifications->notifyAdmins(
            Notification::TYPE_TICKET_USER_REPLY,
            [
                'user_name'     => $user->name ?? $user->username,
                'ticket_number' => $ticket->ticket_number,
            ],
            'ticket_user_reply:' . $message->id,
        );

        return $message;
    }

    /**
     * An admin replies to a ticket. Internal notes are never shown to the user
     * and do not notify them or change the public status.
     */
    /**
     * @param array<int, UploadedFile> $attachments
     */
    public function adminReply(SupportTicket $ticket, User $admin, string $body, bool $internal = false, array $attachments = []): SupportTicketMessage
    {
        $message = DB::transaction(function () use ($ticket, $admin, $body, $internal, $attachments) {
            $message = $this->storeMessage($ticket, $admin, $body, isAdmin: true, isInternal: $internal, attachments: $attachments);

            if (! $internal) {
                $ticket->update([
                    'status'        => SupportTicket::STATUS_ANSWERED,
                    'last_reply_at' => now(),
                    'user_unread'   => true,
                    'admin_unread'  => false,
                ]);
            }

            return $message;
        });

        if (! $internal && $ticket->user) {
            $this->notifications->notify(
                Notification::TYPE_TICKET_ADMIN_REPLY,
                $ticket->user,
                [
                    'user_name'     => $ticket->user->name ?? $ticket->user->username,
                    'ticket_number' => $ticket->ticket_number,
                ],
                'ticket_admin_reply:' . $message->id,
            );
        }

        return $message;
    }

    public function close(SupportTicket $ticket): void
    {
        $ticket->update(['status' => SupportTicket::STATUS_CLOSED, 'closed_at' => now()]);
    }

    public function reopen(SupportTicket $ticket): void
    {
        $ticket->update(['status' => SupportTicket::STATUS_WAITING_ADMIN, 'closed_at' => null]);
    }

    public function markReadByUser(SupportTicket $ticket): void
    {
        if ($ticket->user_unread) {
            $ticket->update(['user_unread' => false]);
        }
    }

    public function markReadByAdmin(SupportTicket $ticket): void
    {
        if ($ticket->admin_unread) {
            $ticket->update(['admin_unread' => false]);
        }
    }

    // ── Internal ─────────────────────────────────────────────────────────────

    /**
     * @param array<int, UploadedFile> $attachments
     */
    private function storeMessage(SupportTicket $ticket, User $author, string $body, bool $isAdmin, bool $isInternal, array $attachments = []): SupportTicketMessage
    {
        $message = SupportTicketMessage::create([
            'support_ticket_id' => $ticket->id,
            'user_id'           => $author->id,
            'is_admin'          => $isAdmin,
            'is_internal_note'  => $isInternal,
            'body'              => $body,
        ]);

        foreach ($attachments as $file) {
            if ($file instanceof UploadedFile) {
                $this->persistUploadedFile($message, $file);
            }
        }

        return $message;
    }

    private function persistUploadedFile(SupportTicketMessage $message, UploadedFile $file): SupportTicketAttachment
    {
        $path = $file->store('support-tickets', 'public');

        return $message->attachments()->create([
            'path'          => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type'     => $file->getClientMimeType(),
            'size'          => $file->getSize(),
        ]);
    }

    /**
     * Attach already-stored files (e.g. uploaded by a Filament FileUpload that
     * stored them on the public disk) to a message.
     *
     * @param array<int, string> $paths
     */
    public function attachStoredPaths(SupportTicketMessage $message, array $paths): void
    {
        foreach ($paths as $path) {
            if (blank($path)) {
                continue;
            }
            $message->attachments()->create([
                'path'          => $path,
                'original_name' => basename($path),
            ]);
        }
    }

    private function ownedOrderId(User $user, ?int $orderId): ?int
    {
        if (! $orderId) {
            return null;
        }
        return Order::where('id', $orderId)->where('user_id', $user->id)->exists() ? $orderId : null;
    }

    private function ownedServiceId(User $user, ?int $serviceId): ?int
    {
        if (! $serviceId) {
            return null;
        }
        return UserService::where('id', $serviceId)->where('user_id', $user->id)->exists() ? $serviceId : null;
    }

    private function normalizePriority(?string $priority): string
    {
        return array_key_exists($priority, SupportTicket::priorities())
            ? $priority
            : SupportTicket::PRIORITY_NORMAL;
    }
}
