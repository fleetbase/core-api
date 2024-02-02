<?php

namespace Fleetbase\Mail;

use Fleetbase\Models\VerificationCode;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\HtmlString;

class VerifyEmail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public string $verifyCode;
    public string $greeting;
    public string $messageSubject;
    public array $lines = [];

    /**
     * Create a new message instance.
     *
     * @return void
     */
    public function __construct($verificationCode, string $subject = null, array $lines = [], Model $user = null)
    {
        $this->setVerificationCode($verificationCode);
        $this->setSubject($subject);
        $this->setEmailLines($lines);
        $this->setGreeting($user);
    }

    public function setVerificationCode($verificationCode): void
    {
        if ($verificationCode instanceof VerificationCode) {
            $this->verifyCode = $verificationCode->code;
        } else {
            $this->verifyCode = $verificationCode;
        }
    }

    public function setSubject(?string $subject): void
    {
        if (is_string($subject) && !empty($subject)) {
            $this->messageSubject = $subject;
        } else {
            $this->messageSubject = $this->verifyCode . ' is your ' . config('app.name') . ' verification code';
        }
    }

    public function setEmailLines(array $lines = []): void
    {
        if (!empty($lines)) {
            $this->lines = $lines;
        } else {
            $this->lines = [
                'Welcome to ' . config('app.name') . ', use the code below to verify your email address and complete registration to ' . config('app.name') . '.',
                new HtmlString('<br><p style="font-family: monospace;">Your verification code: <strong>' . $this->verifyCode . '</strong></p><br>'),
            ];
        }
    }

    public function setGreeting(Model $user): void
    {
        if ($user && isset($user->name)) {
            $this->greeting = 'Hello, ' . $user->name . '!';
        } else {
            $this->greeting = 'Hello!';
        }
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this
            ->subject($this->messageSubject)
            ->html((new MailMessage())
                    ->greeting($this->greeting)
                    ->lines($this->lines)
                    ->render()
            );
    }
}
