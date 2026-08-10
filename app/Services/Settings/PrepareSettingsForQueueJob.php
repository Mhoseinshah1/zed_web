<?php

namespace App\Services\Settings;

use App\Services\Email\EmailTransportSettingsService;

class PrepareSettingsForQueueJob
{
    public function __construct(
        private readonly SettingsLifecycle $lifecycle,
        private readonly EmailTransportSettingsService $transport,
    ) {}

    public function handle(): void
    {
        $this->lifecycle->reset();
        $this->transport->apply();
    }
}
