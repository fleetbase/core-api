<?php

namespace Fleetbase\Notifications;

use Fleetbase\Models\VerificationCode;
use Fleetbase\Support\Utils;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Asks a user to verify their email address, from a request an administrator
 * made in IAM. The link confirms the address without signing in.
 */
class UserContactVerificationRequested extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The URL where the user confirms the email address.
     */
    public string $url;

    /**
     * Create a new notification instance.
     */
    public function __construct(public VerificationCode $verificationCode)
    {
        $this->url = static::urlFor($verificationCode);
    }

    /**
     * The console page that confirms the verification code.
     */
    public static function urlFor(VerificationCode $verificationCode): string
    {
        return Utils::consoleUrl('auth/verify-contact/' . $verificationCode->uuid, ['code' => $verificationCode->code]);
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
        $user = $this->verificationCode->subject;

        return (new MailMessage())
            ->subject('Verify your ' . config('app.name') . ' email address')
            ->greeting('Hello, ' . data_get($user, 'name', 'there'))
            ->line('Please confirm that ' . data_get($this->verificationCode->meta, 'value') . ' is your email address.')
            ->action('Verify Email', $this->url)
            ->line('If the button does not work, use this verification code: ' . $this->verificationCode->code)
            ->line('This link expires in 48 hours. If you were not expecting this email, you can ignore it.');
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
