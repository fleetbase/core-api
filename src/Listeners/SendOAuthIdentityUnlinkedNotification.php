<?php

namespace Fleetbase\Listeners;

use Fleetbase\Events\OAuthIdentityUnlinked;
use Fleetbase\Notifications\OAuthProviderUnlinked;
use Illuminate\Support\Facades\Log;

/**
 * Emails the account holder when a sign-in provider is removed.
 */
class SendOAuthIdentityUnlinkedNotification
{
    public function handle(OAuthIdentityUnlinked $event): void
    {
        if (empty($event->user->email)) {
            return;
        }

        try {
            $event->user->notify(new OAuthProviderUnlinked((string) $event->provider));
        } catch (\Throwable $e) {
            // The removal has happened; a mail failure must not fail it.
            Log::warning('[OAuth] Could not send the provider-unlinked email.', [
                'user'     => $event->user->uuid,
                'provider' => $event->provider,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
