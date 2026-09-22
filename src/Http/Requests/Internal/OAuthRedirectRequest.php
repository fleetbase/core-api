<?php

namespace Fleetbase\Http\Requests\Internal;

use Fleetbase\Http\Requests\FleetbaseRequest;
use Fleetbase\Services\OAuth\OAuthFlowService;

/**
 * Validates the query parameters that begin an OAuth handshake.
 */
class OAuthRedirectRequest extends FleetbaseRequest
{
    /**
     * Public: this is the entry point to sign-in, before any session exists.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array
    {
        return [
            'intent' => ['nullable', 'string', 'in:' . OAuthFlowService::INTENT_LOGIN . ',' . OAuthFlowService::INTENT_SIGNUP],
            // Validated as a shape here and re-sanitized in OAuthFlowService before
            // it is stored or reflected — the regex is the first gate, not the only one.
            'return_to' => ['nullable', 'string', 'max:512', 'regex:' . OAuthFlowService::RETURN_PATH_PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'return_to.regex' => 'The return path must be a relative path within the console.',
        ];
    }

    public function intent(): string
    {
        $intent = $this->input('intent');

        return $intent === OAuthFlowService::INTENT_SIGNUP
            ? OAuthFlowService::INTENT_SIGNUP
            : OAuthFlowService::INTENT_LOGIN;
    }

    public function returnTo(): ?string
    {
        $returnTo = $this->input('return_to');

        return is_string($returnTo) && $returnTo !== '' ? $returnTo : null;
    }
}
