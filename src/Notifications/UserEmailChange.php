<?php

namespace Fleetbase\Notifications;

use Fleetbase\Models\VerificationCode;
use Fleetbase\Support\Utils;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserEmailChange extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Instance of the verification code for the email change.
     */
    public ?VerificationCode $verificationCode;

    /**
     * The URL where the user can confirm the email change.
     */
    public string $url;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(?VerificationCode $verificationCode)
    {
        $this->verificationCode = $verificationCode;
        $this->url              = Utils::consoleUrl('auth/confirm-email-change/' . $verificationCode->uuid, ['code' => $verificationCode->code]);
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @return MailMessage
     */
    public function toMail($notifiable)
    {
        $user     = $this->verificationCode->subject;
        $oldEmail = data_get($this->verificationCode->meta, 'old_email');
        $newEmail = data_get($this->verificationCode->meta, 'new_email');

        return (new MailMessage())
            ->subject('Confirm your ' . config('app.name') . ' email change')
            ->greeting('Hello, ' . data_get($user, 'name', 'there'))
            ->line('An email change was requested for your ' . config('app.name') . ' account.')
            ->line('Current login email: ' . $oldEmail)
            ->line('New login email: ' . $newEmail)
            ->line('Confirm this change only if you expected this email. This address will become your login email after confirmation.')
            ->action('Confirm Email Change', $this->url)
            ->line('If the button does not work, use this confirmation code: ' . $this->verificationCode->code)
            ->line('If you did not request this change, you can ignore this email.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            'code' => $this->verificationCode->code,
        ];
    }
}
