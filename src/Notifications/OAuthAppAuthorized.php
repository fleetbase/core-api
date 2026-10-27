<?php

namespace Fleetbase\Notifications;

use Fleetbase\Support\Utils;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an account holder they gave an app access to one of their organizations.
 *
 * A security notice, like OAuthProviderLinked: an app with access to an organization can act on
 * it until the access is removed, so the owner hears about it whether or not they did it.
 */
class OAuthAppAuthorized extends Notification implements ShouldQueue
{
    use Queueable;

    public string $authorizedAt;

    /**
     * @param array<int, string> $scopeDescriptions
     */
    public function __construct(
        public string $appName,
        public ?string $organizationName,
        public array $scopeDescriptions,
    ) {
        $this->authorizedAt = now()->toDayDateTimeString() . ' UTC';
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
        $subject = $this->organizationName
            ? $this->appName . ' can now access ' . $this->organizationName
            : $this->appName . ' can now sign you in with ' . $app;

        $message = (new MailMessage())
            ->subject($subject)
            ->greeting('Hello, ' . ($notifiable->name ?? 'there'))
            ->line($this->organizationName
                ? 'You gave ' . $this->appName . ' access to ' . $this->organizationName . ' on ' . $app . '. It can:'
                : 'You signed in to ' . $this->appName . ' with your ' . $app . ' account. It can:');

        foreach ($this->scopeDescriptions as $description) {
            $message->line('• ' . $description);
        }

        return $message
            ->line('Authorized: ' . $this->authorizedAt)
            ->action('Review connected apps', Utils::consoleUrl('account/auth'))
            ->line('If this was not you, remove the app from your connected apps and change your password straight away.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray($notifiable)
    {
        return [
            'app'           => $this->appName,
            'organization'  => $this->organizationName,
            'scopes'        => $this->scopeDescriptions,
            'authorized_at' => $this->authorizedAt,
        ];
    }
}
