<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The admin "send test email" message: a harmless configuration probe that is
 * clearly NOT a verification code — sending 000000-style fake OTPs as tests
 * would train recipients to trust look-alike phishing mail. Contains no
 * secrets, no codes, no user data.
 */
class TestEmailMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ایمیل آزمایشی — بررسی پیکربندی ارسال ZED PROXY',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.test',
            text: 'emails.test-text',
        );
    }
}
