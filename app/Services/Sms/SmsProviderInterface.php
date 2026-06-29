<?php

namespace App\Services\Sms;

interface SmsProviderInterface
{
    /**
     * Send an OTP code to a normalized phone number.
     */
    public function sendOtp(string $normalizedPhone, string $code): bool;

    /**
     * Send a free-form message to a normalized phone number.
     *
     * Implementations must throw \RuntimeException with a safe (no-credential)
     * message on failure so callers can surface it to admin logs.
     */
    public function sendMessage(string $normalizedPhone, string $message): bool;
}
