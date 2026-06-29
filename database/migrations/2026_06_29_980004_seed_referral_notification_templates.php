<?php

use App\Services\Notifications\NotificationService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(NotificationService::class)->seedDefaults();
    }

    public function down(): void
    {
        // Templates are user-editable; leave them in place on rollback.
    }
};
