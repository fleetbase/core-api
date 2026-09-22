<?php

namespace Fleetbase\Listeners;

use Fleetbase\Events\OAuthIdentityLinked;
use Fleetbase\Notifications\OAuthProviderLinked;
use Illuminate\Support\Facades\Log;

/**
 * Emails the account holder when a sign-in provider is linked.
 *
 * Not for a provider linked while creating the account: that person has just
 * chosen it, and the signup emails already cover the new account.
 */
class SendOAuthIdentityLinkedNotification
{
    public function handle(OAuthIdentityLinked $event): void
    {
        if ($event->method === OAuthIdentityLinked::METHOD_SIGNUP || empty($event->user->email)) {
            return;
        }

        try {
            $event->user->notify(new OAuthProviderLinked(
                (string) $event->identity->provider,
                $event->identity->provider_email,
                $event->method
            ));
        } catch (\Throwable $e) {
            // The link has happened; a mail failure must not undo or fail it.
            Log::warning('[OAuth] Could not send the provider-linked email.', [
                'user'     => $event->user->uuid,
                'provider' => $event->identity->provider,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
