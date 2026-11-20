<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Generated secrets only live in transient synchronous mail delivery; never queued or logged. */
final class GuestBookingRequestLinksMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $reference,
        public readonly string $verifyUrl,
        public readonly string $manageUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Verify your Bablys travel booking request');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.guest-booking-request');
    }
}
