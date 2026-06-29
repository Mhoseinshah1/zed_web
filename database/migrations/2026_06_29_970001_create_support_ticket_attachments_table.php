<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multiple attachments per ticket message. The legacy single-column
 * attachment_path/attachment_name on support_ticket_messages is kept for old
 * data and still rendered, but new uploads go here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('support_ticket_attachments')) {
            return;
        }

        Schema::create('support_ticket_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_message_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_attachments');
    }
};
