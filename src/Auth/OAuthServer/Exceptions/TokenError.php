<?php

namespace Fleetbase\Auth\OAuthServer\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * A request to an OAuth-protected endpoint that the token doesn't allow (RFC 6750 §3).
 */
class TokenError extends \RuntimeException
{
    public function __construct(
        public string $error,
        public ?string $description = null,
        public int $status = 401,
        public ?string $scope = null,
    ) {
        parent::__construct($description ?: $error);
    }

    public static function invalidToken(string $description = 'The access token is invalid, expired or revoked.'): self
    {
        return new self('invalid_token', $description, 401);
    }

    public static function insufficientScope(string $scope): self
    {
        return new self('insufficient_scope', "This request needs the `{$scope}` scope.", 403, $scope);
    }

    public function toResponse(): JsonResponse
    {
        $challenge = 'Bearer error="' . $this->error . '"';
        if ($this->description) {
            $challenge .= ', error_description="' . addslashes($this->description) . '"';
        }
        if ($this->scope) {
            $challenge .= ', scope="' . $this->scope . '"';
        }

        return new JsonResponse(
            array_filter(['error' => $this->error, 'error_description' => $this->description]),
            $this->status,
            ['WWW-Authenticate' => $challenge, 'Cache-Control' => 'no-store']
        );
    }
}
