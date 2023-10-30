<?php

namespace Fleetbase\Notifications;

use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Fleetbase\Support\Utils;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Class UserAcceptedCompanyInvite
 *
 * Notification for when a user accepts an invitation to a company.
 */
class UserAcceptedCompanyInvite extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The company that the user has joined.
     *
     * @var \Fleetbase\Models\Company
     */
    public Company $company;

    /**
     * The user who has accepted the invite.
     *
     * @var \Fleetbase\Models\User
     */
    public User $user;

    /**
     * The notification name.
     *
     * @var string
     */
    public static string $name = 'User Accepted Company Invite';

    /**
     * The notification description.
     *
     * @var string
     */
    public static string $description = 'Notification sent when a user has accepted a company invite.';

    /**
     * The notification package.
     *
     * @var string
     */
    public static string $package = 'core';

    /**
     * Create a new notification instance.
     *
     * @param \Fleetbase\Models\Company $company The company model instance.
     * @param \Fleetbase\Models\User $user The user model instance.
     */
    public function __construct(Company $company, User $user)
    {
        $this->company = $company;
        $this->user = $user;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param mixed $notifiable The notifiable entity.
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
     * @param mixed $notifiable The notifiable entity.
     *
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        return (new MailMessage())
            ->subject($this->user->name . ' has joined ' . $this->company->name . ' on Fleetbase!')
            ->greeting('Hello, Team!')
            ->line($this->user->name . ' has accepted the invitation and has joined ' . $this->company->name . ' on Fleetbase.')
            ->line('Please welcome them to the team.')
            ->action('View Team Members', Utils::consoleUrl('iam/users'))
            ->line('Thank you for using Fleetbase!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @param mixed $notifiable The notifiable entity.
     *
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ];
    }
}
