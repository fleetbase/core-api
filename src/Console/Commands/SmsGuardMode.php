<?php

namespace Fleetbase\Console\Commands;

use Fleetbase\Support\Sms\SmsGuard;
use Fleetbase\Support\Sms\SmsPolicy;
use Illuminate\Console\Command;

/**
 * Show or switch the SMS guard's mode at runtime.
 *
 * `enforce` refuses sends that break the policy; `monitor` lets them through and logs
 * what would have been refused (`sms.would_block`). The switch is a stored setting, so
 * it applies within a minute on every worker without a deployment.
 */
class SmsGuardMode extends Command
{
    protected $signature = 'sms:mode {mode? : enforce or monitor; omit to show the current mode and pauses}';

    protected $description = 'Show or set the SMS guard mode (enforce or monitor)';

    public function handle(): int
    {
        $mode = $this->argument('mode');

        if ($mode !== null) {
            $mode = strtolower(trim((string) $mode));
            if (!in_array($mode, [SmsPolicy::MODE_ENFORCE, SmsPolicy::MODE_MONITOR], true)) {
                $this->error('Mode must be "enforce" or "monitor".');

                return self::FAILURE;
            }

            SmsPolicy::update(['mode' => $mode]);
            $this->info('SMS guard mode set to ' . $mode . '.');
        }

        $settings = SmsPolicy::settings();
        $this->line('Enabled: ' . ($settings['enabled'] ? 'yes' : 'no'));
        $this->line('Mode:    ' . $settings['mode']);

        $pauses = SmsGuard::make()->breaker()->pauses();
        if (!$pauses) {
            $this->line('Pauses:  none');

            return self::SUCCESS;
        }

        $this->line('Pauses:');
        $this->table(['Scope', 'Until', 'Reason', 'Automatic'], collect($pauses)->map(fn ($pause, $scope) => [
            $scope,
            $pause['until'] === null ? 'unpaused manually' : date('c', $pause['until']),
            $pause['reason'],
            $pause['auto'] ? 'yes' : 'no',
        ])->values()->all());

        return self::SUCCESS;
    }
}
