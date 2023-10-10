<?php

namespace Fleetbase\Notifications;

use Fleetbase\Models\Company;
use Fleetbase\Models\Invite;
use Fleetbase\Models\User;
use Fleetbase\Support\Utils;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

class UserInvited extends Notification implements ShouldQueue
{
    use Queueable;

    public Invite $invite;
    public Company $company;
    public User $sender;
    public string $url;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(Invite $invite)
    {
        $this->invite  = $invite;
        $this->company = $this->invite->subject;
        $this->sender  = $this->invite->createdBy;
        $this->url     = Utils::consoleUrl('join/org/' . $invite->uri);
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
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        return (new MailMessage())
            ->subject('You\'ve been invited to join ' . $this->company->name . ' on Fleetbase!')
            ->greeting('Hello, ' . $notifiable->name . '!')
            ->line($this->sender->name . ' has invited you to join their organization on Fleetbase. Click the button below to accept this invitation and enable access to ' . $this->company->name . ' on Fleetbase.')
            ->line(new HtmlString('<br><p style="font-family: monospace;">Your invitiation code: <strong>' . $this->invite->code . '</strong></p>'))
            ->action('Accept Invitation', $this->url);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
        ];
    }
}
