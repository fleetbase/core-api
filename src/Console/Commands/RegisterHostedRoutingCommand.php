<?php

namespace Fleetbase\Console\Commands;

use Fleetbase\Support\HostedRouting;
use Illuminate\Console\Command;

/**
 * Turns on Fleetbase Hosted Routing for this install: registers it with
 * Fleetbase Cloud for a free routing key and stores the key in the system
 * Valhalla settings. For installs set up without the onboarding screen.
 */
class RegisterHostedRoutingCommand extends Command
{
    protected $signature = 'routing:register
                            {--email= : Administrator email to register with (default: the first admin)}
                            {--domain= : URL of this install (default: APP_URL)}
                            {--force : Register even if turned off, already registered or using another Valhalla server}';

    protected $description = 'Get a free Fleetbase Hosted Routing key for this install';

    public function handle(): int
    {
        $this->line('Fleetbase Hosted Routing: routes, distances and ETAs are computed on Fleetbase servers in Singapore (DigitalOcean sgp1).');
        $this->line('This sends the install ID, the admin email and this install\'s URL to Fleetbase Cloud. Route coordinates are sent with each routing request and are not logged.');

        $result = HostedRouting::register($this->option('email') ?: null, $this->option('domain') ?: null, (bool) $this->option('force'));

        if ($result['status'] === HostedRouting::STATUS_FAILED) {
            $this->error($result['message']);

            return self::FAILURE;
        }

        if ($result['status'] === HostedRouting::STATUS_SKIPPED) {
            $this->info($result['message']);

            return self::SUCCESS;
        }

        $this->info($result['message'] . ' Plan: ' . ($result['plan'] ?? 'unknown') . '.');
        if (!$result['email_verified']) {
            $this->line('Confirm the email sent to ' . $result['email'] . ' to raise the limit from 500 to 2,500 routes a day.');
        }
        if (!empty($result['claim_url'])) {
            $this->line('Upgrade later from Admin → Fleet-Ops → Routing, or at ' . $result['claim_url']);
        }

        return self::SUCCESS;
    }
}
