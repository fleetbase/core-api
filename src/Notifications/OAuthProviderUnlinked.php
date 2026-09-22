<?php

namespace Fleetbase\Notifications;

use Fleetbase\Support\OAuth;
use Fleetbase\Support\Utils;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an account holder a sign-in provider was removed from their account.
 *
 * Someone who got into the account could remove the owner's provider to lock them
 * out of that route, so the owner is told either way.
 */
class OAuthProviderUnlinked extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * When it was removed, fixed at the moment of removal rather than of sending.
     */
    public string $unlinkedAt;

    public function __construct(public string $provider)
    {
        $this->unlinkedAt = now()->toDayDateTimeString() . ' UTC';
    }

    /**
     * @return array<int, string>
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * @return MailMessage
     */
    public function toMail($notifiable)
    {
        $app   = config('app.name');
        $label = OAuth::providerLabel($this->provider);

        return (new MailMessage())
            ->subject($label . ' was removed from your ' . $app . ' account')
            ->greeting('Hello, ' . ($notifiable->name ?? 'there'))
            ->line($label . ' was removed from the sign-in methods on your ' . $app . ' account. You can no longer sign in with ' . $label . '.')
            ->line('Removed: ' . $this->unlinkedAt)
            ->action('Review sign-in methods', Utils::consoleUrl('account/auth'))
            ->line('If this was not you, change your password straight away and review your sign-in methods.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray($notifiable)
    {
        return [
            'provider'    => $this->provider,
            'unlinked_at' => $this->unlinkedAt,
        ];
    }
}
