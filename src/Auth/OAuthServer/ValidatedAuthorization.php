<?php

namespace Fleetbase\Auth\OAuthServer;

use Fleetbase\Models\OAuthClient;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;

/**
 * An authorization request that passed every check and can be shown on the consent screen.
 */
class ValidatedAuthorization
{
    /**
     * @param array<int, string> $scopes
     * @param array<int, string> $prompt
     */
    public function __construct(
        public OAuthClient $client,
        public AuthorizationRequest $request,
        public array $scopes,
        public string $redirectUri,
        public ?string $state,
        public ?string $nonce,
        public array $prompt,
        public ?int $maxAge,
    ) {
    }

    public function requiresOrganization(): bool
    {
        return OAuthScopes::requiresOrganization($this->scopes);
    }

    public function prompts(string $value): bool
    {
        return in_array($value, $this->prompt, true);
    }
}
