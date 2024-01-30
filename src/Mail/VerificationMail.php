<?php

namespace Fleetbase\Mail;

use Fleetbase\Models\VerificationCode;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Queue\SerializesModels;
// use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Bus\Queueable;

class VerificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * The verification code to email.
     *
     * @var \Fleetbase\Models\VerificationCode
     */
    private VerificationCode $verificationCode;

    /**
     * Custom content to render if supplied.
     *
     * @var string|null
     */
    private ?string $content;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(VerificationCode $verificationCode, ?string $content = null)
    {
        $this->verificationCode = $verificationCode;
        $this->content = $content;
    }

    /**
     * Get the message content definition.
     * 
     * @return \Illuminate\Mail\Mailables\Envelope
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->verificationCode->code . ' is your ' . config('app.name') . ' verification code',
        );
    }

    /**
     * Get the message content definition.
     * 
     * @return \Illuminate\Mail\Mailables\Content
     */
    public function content(): Content
    {
        return new Content(
            html: 'fleetbase::mail.verification',
            with: [
                'appName' => config('app.name'),
                'currentHour' => now()->hour,
                'user' => $this->verificationCode->subject,
                'code' => $this->verificationCode->code,
                'content' => $this->content
            ]
        );
    }
}
