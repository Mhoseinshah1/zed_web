<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The 6-digit PASSWORD-RESET code email (Persian RTL HTML + plain-text
 * alternative). Sent by SendPasswordResetOtpJob; contains no password, no
 * link, no internal IDs — only the code and its validity window.
 */
class PasswordResetOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $code,
        public readonly int $ttlMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'کد بازیابی رمز عبور — ZED PROXY',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.password-reset-otp',
            text: 'emails.password-reset-otp-text',
            with: [
                'code' => $this->code,
                'ttlMinutes' => $this->ttlMinutes,
            ],
        );
    }
}
