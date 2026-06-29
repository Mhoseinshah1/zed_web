<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SupportTicketAttachment extends Model
{
    /** Extensions rendered as inline image previews. */
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    protected $fillable = [
        'support_ticket_message_id',
        'path',
        'original_name',
        'mime_type',
        'size',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportTicketMessage::class, 'support_ticket_message_id');
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->original_name ?: $this->path, PATHINFO_EXTENSION));
    }

    public function isImage(): bool
    {
        return in_array($this->extension(), self::IMAGE_EXTENSIONS, true);
    }

    public function isPdf(): bool
    {
        return $this->extension() === 'pdf';
    }

    public function exists(): bool
    {
        return filled($this->path) && Storage::disk('public')->exists($this->path);
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    public function displayName(): string
    {
        return $this->original_name ?: Str::afterLast($this->path, '/');
    }
}
