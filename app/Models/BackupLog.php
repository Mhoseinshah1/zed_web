<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupLog extends Model
{
    public const STATUS_STARTED = 'started';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';

    public const TYPE_MANUAL    = 'manual';
    public const TYPE_SCHEDULED = 'scheduled';

    protected $fillable = [
        'type', 'status', 'file_path', 'file_size', 'duration_ms',
        'error', 'sent_to_telegram', 'metadata', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'file_size'        => 'integer',
        'duration_ms'      => 'integer',
        'sent_to_telegram' => 'boolean',
        'metadata'         => 'array',
        'started_at'       => 'datetime',
        'finished_at'      => 'datetime',
    ];

    public static function latestLog(): ?self
    {
        return static::latest('id')->first();
    }

    /** Human-readable size (MB). */
    public function sizeMb(): float
    {
        return round(((int) $this->file_size) / 1048576, 2);
    }
}
