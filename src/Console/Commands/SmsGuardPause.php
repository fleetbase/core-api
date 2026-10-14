<?php

namespace Fleetbase\Console\Commands;

use Fleetbase\Support\Sms\SmsBreaker;
use Fleetbase\Support\Sms\SmsGuard;
use Illuminate\Console\Command;

/**
 * Pause SMS for a company, a store or network, or the whole platform.
 *
 * The kill switch for an incident: paused scopes are refused by the guard at once, on
 * every worker, and flows with an email fallback keep working by email.
 */
class SmsGuardPause extends Command
{
    protected $signature = 'sms:pause
                            {scope : a company uuid or public id, "platform", or a scope key such as company:<uuid> or tenant:store:<uuid>}
                            {--minutes= : how long to pause for; omit to pause until sms:unpause}
                            {--reason=manual : why, shown on the admin page and in alerts}';

    protected $description = 'Pause SMS sending for a company, a store/network or the platform';

    public function handle(): int
    {
        $scope = static::resolveScope((string) $this->argument('scope'));
        if ($scope === null) {
            $this->error('No company matches "' . $this->argument('scope') . '". Pass a company uuid or public id, "platform", or a scope key.');

            return self::FAILURE;
        }

        $minutes = $this->option('minutes');
        $minutes = $minutes === null || $minutes === '' ? null : max(1, (int) $minutes);

        $pause = SmsGuard::make()->breaker()->pause($scope, $minutes, (string) $this->option('reason'), false, static::companyUuidFromScope($scope));

        $this->info('SMS paused for ' . $scope . ($pause['until'] === null ? ' until sms:unpause is run.' : ' until ' . date('c', $pause['until']) . '.'));

        return self::SUCCESS;
    }

    /**
     * A scope key for what the operator typed, or null when a company could not be found.
     */
    public static function resolveScope(string $input): ?string
    {
        return SmsBreaker::resolveScope($input);
    }

    public static function companyUuidFromScope(string $scope): ?string
    {
        return SmsBreaker::companyUuidFromScope($scope);
    }
}
