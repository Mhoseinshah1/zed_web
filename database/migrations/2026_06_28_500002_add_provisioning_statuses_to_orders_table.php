<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    // Order status is a plain varchar — no enum change needed.
    // New values 'provisioning' and 'provisioning_failed' are valid string values
    // and do not require a schema change.
    public function up(): void {}

    public function down(): void {}
};
