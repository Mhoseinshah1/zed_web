<?php

use App\Models\SupportTicketCategory;
use Illuminate\Database\Migrations\Migration;

/**
 * Seeds default support ticket categories only when missing.
 * Existing (admin-edited) categories are never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (SupportTicketCategory::defaults() as $i => $name) {
            SupportTicketCategory::firstOrCreate(
                ['name' => $name],
                ['is_active' => true, 'sort_order' => $i],
            );
        }
    }

    public function down(): void
    {
        // Categories are user-editable content; leave them in place on rollback.
    }
};
