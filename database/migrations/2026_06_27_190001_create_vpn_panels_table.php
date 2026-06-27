<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vpn_panels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('marzban'); // marzban | xui | sanaei_3xui | other
            $table->string('base_url')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable(); // store encrypted in future
            $table->text('token')->nullable();     // store encrypted in future
            $table->boolean('is_active')->default(false);
            $table->boolean('is_default')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vpn_panels');
    }
};
