<?php

namespace Database\Seeders;

use App\Models\TelegramAdminTopic;
use App\Services\Telegram\TelegramTemplates;
use Illuminate\Database\Seeder;

class TelegramAdminSeeder extends Seeder
{
    public function run(): void
    {
        TelegramAdminTopic::seedDefaults();
        app(TelegramTemplates::class)->seedDefaults();
    }
}
