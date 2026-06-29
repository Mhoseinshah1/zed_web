<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class SupportTicketMessage extends Model
{
    protected $fillable = [
        'support_ticket_id',
        'user_id',
        'is_admin',
        'is_internal_note',
        'body',
        'attachment_path',
        'attachment_name',
    ];

    protected $casts = [
        'is_admin'         => 'boolean',
        'is_internal_note' => 'boolean',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'support_ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_internal_note', false);
    }

    public function hasAttachment(): bool
    {
        return filled($this->attachment_path) || $this->attachments()->exists();
    }

    /**
     * Unified, view-ready attachment list — merges the legacy single column
     * with the new attachments table. Each item is a normalized array:
     *   ['name', 'url', 'is_image', 'is_pdf', 'exists', 'ext'].
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function displayAttachments(): Collection
    {
        $items = collect();

        // New multi-attachments.
        foreach ($this->attachments as $attachment) {
            $items->push([
                'name'     => $attachment->displayName(),
                'url'      => $attachment->url(),
                'is_image' => $attachment->isImage(),
                'is_pdf'   => $attachment->isPdf(),
                'exists'   => $attachment->exists(),
                'ext'      => $attachment->extension(),
            ]);
        }

        // Legacy single attachment.
        if (filled($this->attachment_path)) {
            $ext = strtolower(pathinfo($this->attachment_name ?: $this->attachment_path, PATHINFO_EXTENSION));
            $items->push([
                'name'     => $this->attachment_name ?: basename($this->attachment_path),
                'url'      => Storage::disk('public')->url($this->attachment_path),
                'is_image' => in_array($ext, SupportTicketAttachment::IMAGE_EXTENSIONS, true),
                'is_pdf'   => $ext === 'pdf',
                'exists'   => Storage::disk('public')->exists($this->attachment_path),
                'ext'      => $ext,
            ]);
        }

        return $items;
    }
}
