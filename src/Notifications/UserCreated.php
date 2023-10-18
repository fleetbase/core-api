<?php

namespace Fleetbase\Notifications;

use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Bus\Queueable;
// use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

class UserCreated extends Notification
{
    use Queueable;

    public ?User $user;
    public ?Company $company;
    public ?string $sentAt;
    public ?string $notificationId;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct(User $user, Company $company)
    {
        $this->user    = $user;
        $this->company = $company;
        $this->sentAt  = Carbon::now()->toDateTimeString();
        $this->notificationId = uniqid('notification_');
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail', 'database', 'broadcast'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        return (new MailMessage())
            ->subject('🥳 New Fleetbase Signup!')
            ->line('View user details below.')
            ->line('Name: ' . $this->user->name)
            ->line('Email: ' . $this->user->email)
            ->line('Phone: ' . $this->user->phone)
            ->line('Company: ' . $this->company->name);
    }

    /**
     * Get the broadcastable representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\BroadcastMessage
     */
    public function toBroadcast($notifiable)
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            'id'          => $this->notificationId,
            'created_at'  => $this->sentAt,
            'notifiable' => $notifiable->{$notifiable->getKeyName()},
            'data'        => [
                'subject' => '🥳 New Fleetbase Signup!',
                'message' => 'New user ' . $this->user->name . ' added to organization ' . $this->company->name,
                'id' => $this->user->uuid,
                'email' => $this->user->email,
                'phone' => $this->user->phone,
                'companyId' => $this->company->uuid,
                'company' => $this->company->name,
            ],
        ];
    }
}
