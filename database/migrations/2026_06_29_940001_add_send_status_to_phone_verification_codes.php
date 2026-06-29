<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('phone_verification_codes', function (Blueprint $table) {
            if (! Schema::hasColumn('phone_verification_codes', 'send_status')) {
                $table->string('send_status', 20)->nullable()->after('attempts');
            }
            if (! Schema::hasColumn('phone_verification_codes', 'send_error')) {
                $table->text('send_error')->nullable()->after('send_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('phone_verification_codes', function (Blueprint $table) {
            $table->dropColumn(['send_status', 'send_error']);
        });
    }
};
