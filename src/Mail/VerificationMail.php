<?php

namespace Fleetbase\Mail;

use Fleetbase\Models\VerificationCode;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class VerificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * The verification code to email.
     */
    public VerificationCode $verificationCode;

    /**
     * Custom content to render if supplied.
     */
    public ?string $content;

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct(VerificationCode $verificationCode, ?string $content = null)
    {
        $this->verificationCode = $verificationCode;
        $this->content          = $content;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this
            ->subject($this->verificationCode->code . ' is your ' . config('app.name') . ' verification code')
            ->markdown('fleetbase::mail.verification', [
                'appName'     => config('app.name'),
                'currentHour' => now()->hour,
                'user'        => $this->verificationCode->subject,
                'code'        => $this->verificationCode->code,
                'type'        => $this->verificationCode->for,
                'content'     => $this->content,
            ]);
    }
}
