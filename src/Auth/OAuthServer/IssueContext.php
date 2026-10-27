<?php

namespace Fleetbase\Auth\OAuthServer;

/**
 * What a code or token being issued is bound to.
 *
 * League's repositories only see the client, the user and the scopes. The consent step (when it
 * issues a code) and the token endpoint (when it redeems a code or a refresh token) fill this in,
 * and the repositories copy it onto the rows they write. It is a plain object built per request,
 * never a container singleton, so nothing leaks between requests under Octane.
 */
class IssueContext
{
    public ?string $grantUuid = null;

    public ?string $companyUuid = null;

    public ?string $nonce = null;

    public ?\DateTimeInterface $authTime = null;

    /**
     * The code a token chain started from.
     */
    public ?string $authCodeId = null;

    public static function forGrant(string $grantUuid, ?string $companyUuid): self
    {
        $context              = new self();
        $context->grantUuid   = $grantUuid;
        $context->companyUuid = $companyUuid;

        return $context;
    }
}
