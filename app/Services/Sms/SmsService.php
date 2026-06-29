<?php

namespace App\Services\Sms;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Log;

/**
 * Thin SMS gateway abstraction.
 *
 * No real SMS provider is integrated yet. Sending is therefore disabled by
 * default; calls are logged so the OTP flow keeps working end-to-end without
 * crashing. When a provider is added later, wire it inside send() and flip the
 * `sms_provider_configured` site setting to true.
 */
class SmsService
{
    public function isConfigured(): bool
    {
        return (bool) SiteSetting::get('sms_provider_configured', false);
    }

    /**
     * Attempt to send an SMS. Returns true if it was actually dispatched.
     */
    public function send(string $normalizedPhone, string $message): bool
    {
        if (! $this->isConfigured()) {
            Log::info('SmsService: SMS provider not configured — message not sent', [
                'phone' => $normalizedPhone,
            ]);
            return false;
        }

        // No provider integration yet. When one is added, dispatch here.
        Log::warning('SmsService: configured flag set but no provider implementation — message not sent', [
            'phone' => $normalizedPhone,
        ]);

        return false;
    }
}
