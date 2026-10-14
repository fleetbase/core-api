<?php

namespace Fleetbase\Console\Commands;

use Fleetbase\Support\Sms\SmsGuard;
use Illuminate\Console\Command;

/**
 * Lift an SMS pause set by the breaker or by sms:pause.
 */
class SmsGuardUnpause extends Command
{
    protected $signature = 'sms:unpause {scope : a company uuid or public id, "platform", or a scope key such as company:<uuid>}';

    protected $description = 'Resume SMS sending for a paused company, store/network or the platform';

    public function handle(): int
    {
        $scope = SmsGuardPause::resolveScope((string) $this->argument('scope'));
        if ($scope === null) {
            $this->error('No company matches "' . $this->argument('scope') . '".');

            return self::FAILURE;
        }

        SmsGuard::make()->breaker()->unpause($scope);
        $this->info('SMS resumed for ' . $scope . '.');

        return self::SUCCESS;
    }
}
