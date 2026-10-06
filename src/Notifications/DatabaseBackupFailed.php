<?php

namespace Fleetbase\Notifications;

use Fleetbase\Models\DatabaseBackup;
use Fleetbase\Support\Utils;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the addresses configured under Admin → Database Backups that a run failed.
 *
 * Sent synchronously: when a backup fails the queue may be part of what is broken.
 */
class DatabaseBackupFailed extends Notification
{
    /**
     * @var array<int, array{connection: string, database: string, error: string|null}>
     */
    public array $failures;

    /**
     * @param array<int, DatabaseBackup> $failed
     */
    public function __construct(array $failed)
    {
        $this->failures = array_map(fn (DatabaseBackup $backup) => [
            'connection' => (string) $backup->connection_name,
            'database'   => (string) $backup->database,
            'error'      => $backup->error,
        ], $failed);
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
        $message = (new MailMessage())
            ->error()
            ->subject($app . ' database backup failed')
            ->line('The database backup that just ran did not complete, so no new backup was stored for:');

        foreach ($this->failures as $failure) {
            $message->line($failure['database'] . ' (' . $failure['connection'] . '): ' . ($failure['error'] ?: 'unknown error'));
        }

        return $message
            ->line('Older backups were left in place.')
            ->action('Review database backups', Utils::consoleUrl('admin/database-backups'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray($notifiable)
    {
        return ['failures' => $this->failures];
    }
}
