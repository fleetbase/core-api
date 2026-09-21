<?php

namespace Fleetbase\Http\Requests\Admin;

use Fleetbase\Http\Requests\AdminRequest;

/**
 * Validates an administrator's OAuth configuration change.
 *
 * Validation here is about shape. The security-relevant filtering — which provider
 * ids exist, which fields each accepts, which of those are secrets — is done against
 * the driver schemas in SettingController::saveOAuthConfig(), so a field that is not
 * declared by a driver can never be written no matter what passes this request.
 */
class SaveOAuthConfigRequest extends AdminRequest
{
    /**
     * @return array<string, array<int, mixed>|string>
     */
    public function rules()
    {
        return [
            'enabled'             => ['sometimes', 'boolean'],
            'allow_registration'  => ['sometimes', 'boolean'],
            'providers'           => ['sometimes', 'array'],
            'providers.*'         => ['array'],
            'providers.*.enabled' => ['sometimes', 'boolean'],

            'providers.*.client_id'     => ['nullable', 'string', 'max:512'],
            'providers.*.client_secret' => ['nullable', 'string', 'max:4096'],
            'providers.*.hosted_domain' => ['nullable', 'string', 'max:253'],
            'providers.*.tenant'        => ['nullable', 'string', 'max:255'],
            'providers.*.team_id'       => ['nullable', 'string', 'max:64'],
            'providers.*.key_id'        => ['nullable', 'string', 'max:64'],
            // An empty value means "keep the stored key", so only a non-empty value is
            // checked for the PEM header. Caught here rather than at first sign-in, where
            // it would surface as a confusing provider error.
            'providers.*.private_key' => ['nullable', 'string', 'max:8192', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_string($value) && trim($value) !== '' && !str_starts_with(trim($value), '-----BEGIN')) {
                    $fail('The signing key must be the full contents of the .p8 file, beginning with -----BEGIN PRIVATE KEY-----.');
                }
            }],
        ];
    }
}
