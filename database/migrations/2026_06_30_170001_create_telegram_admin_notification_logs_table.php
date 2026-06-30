<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_admin_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event_key')->index();
            $table->string('topic_key')->index();
            $table->string('chat_id')->nullable();
            $table->unsignedBigInteger('message_thread_id')->nullable();
            $table->string('title')->nullable();
            $table->text('message');
            $table->string('status')->default('pending')->index(); // pending|sent|failed|skipped|muted
            $table->unsignedBigInteger('telegram_message_id')->nullable();
            $table->text('error')->nullable();
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['related_type', 'related_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_admin_notification_logs');
    }
};
