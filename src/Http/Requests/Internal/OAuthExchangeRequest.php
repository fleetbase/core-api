<?php

namespace Fleetbase\Http\Requests\Internal;

use Fleetbase\Http\Requests\FleetbaseRequest;

/**
 * Validates the one-time handoff code the console trades for a session.
 */
class OAuthExchangeRequest extends FleetbaseRequest
{
    /**
     * Public: the console has no session yet — obtaining one is the point.
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
            // Handoff codes are a fixed 64 characters; anything else is not one, and
            // rejecting on shape keeps malformed input away from a database lookup.
            'code' => ['required', 'string', 'size:64'],
        ];
    }

    public function code(): string
    {
        return (string) $this->input('code');
    }
}
