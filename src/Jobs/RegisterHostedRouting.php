<?php

namespace Fleetbase\Jobs;

use Fleetbase\Support\HostedRouting;
use Illuminate\Bus\Dispatcher;

/**
 * Registers the install for a Fleetbase Hosted Routing key after the first
 * admin's onboarding response is sent, so signup never waits on Fleetbase
 * Cloud. Runs in the same process (dispatchAfterResponse), so it needs no
 * queue worker. A failure only logs; the admin can run
 * `php artisan routing:register` later.
 */
class RegisterHostedRouting
{
    public function __construct(public string $email, public ?string $domain = null)
    {
    }

    /**
     * Run once the current response has been sent.
     */
    public static function afterResponse(string $email, ?string $domain = null): void
    {
        app(Dispatcher::class)->dispatchAfterResponse(new static($email, $domain));
    }

    public function handle(): void
    {
        HostedRouting::register($this->email, $this->domain);
    }
}
