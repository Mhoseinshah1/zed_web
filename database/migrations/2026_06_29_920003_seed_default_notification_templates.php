<?php

use App\Services\Notifications\NotificationService;
use Illuminate\Database\Migrations\Migration;

/**
 * Seeds the built-in notification templates only when they are missing.
 * Existing (admin-edited) templates are never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(NotificationService::class)->seedDefaults();
    }

    public function down(): void
    {
        // Templates are user-editable content; leave them in place on rollback.
    }
};
