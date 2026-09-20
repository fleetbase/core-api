<?php

namespace Fleetbase\Listeners;

use Fleetbase\Events\AccountCreated;
use Fleetbase\Models\VerificationCode;

class HandleAccountCreated
{
    /**
     * Handle the event.
     *
     * @param object $event
     *
     * @return void
     */
    public function handle(AccountCreated $event)
    {
        // Send user a verification email
        $user = $event->user;

        // isNotVerified() guards the OAuth signup case: a provider that vouched for the
        // address means the account is already verified, and sending a code to it would
        // be noise the user cannot act on. A password signup is never verified at this
        // point, so this is a no-op there.
        if ($user && $user->isNotAdmin() && $user->isNotVerified()) {
            // Create and send verification code
            try {
                VerificationCode::generateEmailVerificationFor($user);
            } catch (\Throwable $e) {
                // If phone number is supplied send via SMS
                if ($user->phone) {
                    try {
                        VerificationCode::generateSmsVerificationFor($user);
                    } catch (\Throwable $e) {
                        // silence
                    }
                }
            }
        }
    }
}
