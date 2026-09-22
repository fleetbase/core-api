<?php

namespace Fleetbase\Notifications;

use Fleetbase\Events\OAuthIdentityLinked;
use Fleetbase\Support\OAuth;
use Fleetbase\Support\Utils;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an account holder a sign-in provider was linked to their account.
 *
 * A security notice, not a preference: a linked provider is a new way into the
 * account, so its owner hears about it whether or not they did it themselves.
 */
class OAuthProviderLinked extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * When it was linked, fixed at the moment of linking rather than of sending.
     */
    public string $linkedAt;

    public function __construct(
        public string $provider,
        public ?string $providerEmail,
        public string $method = OAuthIdentityLinked::METHOD_MANUAL,
    ) {
        $this->linkedAt = now()->toDayDateTimeString() . ' UTC';
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
        $app     = config('app.name');
        $label   = OAuth::providerLabel($this->provider);
        $account = $this->providerEmail ? $label . ' account (' . $this->providerEmail . ')' : $label . ' account';

        $message = (new MailMessage())
            ->subject($label . ' was linked to your ' . $app . ' account')
            ->greeting('Hello, ' . ($notifiable->name ?? 'there'));

        if ($this->method === OAuthIdentityLinked::METHOD_AUTOMATIC) {
            $message
                ->line('You signed in to ' . $app . ' with your ' . $account . '.')
                ->line('It uses the same verified email address as your ' . $app . ' account, so we linked the two. From now on you can sign in with ' . $label . '.');
        } else {
            $message->line('Your ' . $account . ' was linked to your ' . $app . ' account. From now on you can sign in with ' . $label . '.');
        }

        return $message
            ->line('Linked: ' . $this->linkedAt)
            ->action('Review sign-in methods', Utils::consoleUrl('account/auth'))
            ->line('If this was not you, remove ' . $label . ' from your sign-in methods and change your password straight away.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray($notifiable)
    {
        return [
            'provider'       => $this->provider,
            'provider_email' => $this->providerEmail,
            'method'         => $this->method,
            'linked_at'      => $this->linkedAt,
        ];
    }
}
