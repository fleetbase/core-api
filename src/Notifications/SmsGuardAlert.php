<?php

namespace Fleetbase\Notifications;

use Fleetbase\Models\User;
use Fleetbase\Support\Sms\SmsContext;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the system administrators that the SMS guard paused a tenant, or that a budget
 * is being used up.
 *
 * Sent synchronously, like DatabaseBackupFailed: when SMS is being pumped the queue may be
 * busy, and the point is to be told within minutes. Administrators get mail and an in-app
 * notification; extra addresses from the policy get mail only. Tenants are not told.
 */
class SmsGuardAlert extends Notification
{
    public function __construct(
        public string $signal,
        public string $title,
        public array $lines,
        public array $data = [],
    ) {
    }

    /**
     * Build the alert for a signal, naming the tenant and what tripped it.
     */
    public static function for(string $signal, SmsContext $context, array $data = []): static
    {
        $scope  = (string) ($data['scope'] ?? $context->companyScope() ?? 'platform');
        $mode   = (string) ($data['mode'] ?? 'enforce');
        $where  = 'Scope ' . $scope . ($context->companyUuid ? ' (company ' . $context->companyUuid . ')' : '') . ', purpose ' . $context->purpose . ', source ' . $context->source . '.';
        $paused = array_key_exists('paused_minutes', $data)
            ? ($mode === 'monitor'
                ? 'The guard is in monitor mode, so nothing was paused.'
                : ($data['paused_minutes'] === null
                    ? 'SMS is paused for this scope until an administrator unpauses it.'
                    : 'SMS is paused for this scope for ' . $data['paused_minutes'] . ' minutes.'))
            : null;

        [$title, $what] = match ($signal) {
            'company_daily_budget'           => ['tenant hit its daily SMS budget', 'The tenant sent ' . ($data['sends_today'] ?? '?') . ' messages today against a budget of ' . ($data['budget'] ?? '?') . '.'],
            'company_hourly_budget_repeated' => ['tenant hit its hourly SMS budget twice', 'The tenant reached its hourly budget of ' . ($data['budget'] ?? '?') . ' messages in ' . ($data['hours_at_budget_today'] ?? '?') . ' separate hours today.'],
            'distinct_destinations'          => ['burst of SMS to new numbers', 'One tenant texted ' . ($data['distinct_destinations'] ?? '?') . ' distinct numbers inside ' . ($data['window_minutes'] ?? 10) . ' minutes. This is the SMS pumping pattern.'],
            'company_budget_threshold'       => ['tenant at ' . ($data['percent'] ?? '?') . '% of its daily SMS budget', 'The tenant has sent ' . ($data['sends_today'] ?? '?') . ' of ' . ($data['budget'] ?? '?') . ' messages today.'],
            'platform_budget_threshold'      => ['platform at ' . ($data['percent'] ?? '?') . '% of its hourly SMS budget', 'All tenants together have sent ' . ($data['sends_this_hour'] ?? '?') . ' of ' . ($data['budget'] ?? '?') . ' messages this hour.'],
            default                          => ['SMS guard signal: ' . $signal, 'The SMS guard raised ' . $signal . '.'],
        };

        $lines = array_values(array_filter([
            $what,
            $where,
            $context->ip ? 'Last request from ' . $context->ip . ($context->userAgent ? ' (' . $context->userAgent . ')' : '') . ', to ' . $context->destination . '.' : 'Last message to ' . $context->destination . '.',
            $paused,
            'Review the sms.blocked and sms.sent log entries for this scope. Manage pauses and the mode with the sms:pause, sms:unpause and sms:mode console commands.',
        ]));

        return new static($signal, $title, $lines, array_merge($data, ['context' => $context->toArray()]));
    }

    /**
     * @return array<int, string>
     */
    public function via($notifiable)
    {
        return $notifiable instanceof User ? ['mail', 'database'] : ['mail'];
    }

    /**
     * @return MailMessage
     */
    public function toMail($notifiable)
    {
        $message = (new MailMessage())
            ->error()
            ->subject(config('app.name') . ' SMS guard: ' . $this->title);

        foreach ($this->lines as $line) {
            $message->line($line);
        }

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray($notifiable)
    {
        return [
            'signal' => $this->signal,
            'title'  => $this->title,
            'lines'  => $this->lines,
            'data'   => $this->data,
        ];
    }
}
