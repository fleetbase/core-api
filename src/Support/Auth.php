<?php

namespace Fleetbase\Support;

use Fleetbase\Models\ApiCredential;
use Fleetbase\Models\Company;
use Fleetbase\Models\User;
use Illuminate\Support\Facades\Auth as Authentication;
use Illuminate\Support\Facades\Hash;

class Auth extends Authentication
{
    /**
     * Create company and user.
     *
     * @param array $owner   The owner to be created
     * @param array $company The company to be created
     *
     * @return \Fleetbase\Models\User;
     */
    public static function register($owner, $company)
    {
        // email is always lowercase
        if (isset($owner['email'])) {
            $owner['email'] = strtolower($owner['email']);
        }

        if (isset($company['email'])) {
            $company['email'] = strtolower($company['email']);
        }

        $owner   = User::create($owner);
        $company = Company::create($company)
            ->setOwner($owner)
            ->saveInstance();

        $owner->assignCompany($company);

        return $owner;
    }

    /**
     * Set session variables for user.
     *
     * @param \Fleetbase\Models\User|\Fleetbase\Models\ApiCredential|null $user
     */
    public static function setSession($user = null, $login = false): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user instanceof ApiCredential) {
            $apiCredential = $user;
            session(['company' => $apiCredential->company_uuid, 'user' => $apiCredential->user_uuid]);
            // user couldn't be loaded, fallback with api credential if applicable
            $user = User::find($apiCredential->user_uuid);

            // if still no user -- return true and continue on
            if (!$user) {
                return true;
            }
        }

        session(['company' => $user->company_uuid, 'user' => $user->uuid, 'is_admin' => $user->isAdmin()]);

        if ($login) {
            Authentication::login($user);
        }

        return true;
    }

    /**
     * Set session variables for api credentials being used.
     *
     * @param \Fleetbase\Models\ApiCredential $apiCredential
     *
     * @return bool
     */
    public static function setApiKey($apiCredential)
    {
        session([
            'api_credential'  => $apiCredential->uuid,
            'api_key'         => $apiCredential->key,
            'api_key_version' => $apiCredential->created_at,
            'api_secret'      => $apiCredential->secret,
            'api_environment' => $apiCredential->test_mode ? 'test' : 'live',
            'api_test_mode'   => $apiCredential->test_mode,
        ]);

        return true;
    }

    /**
     * Get the current api key.
     */
    public static function getApiKey(): ?ApiCredential
    {
        if (!session('api_credential')) {
            return null;
        }

        return ApiCredential::where('uuid', session('api_credential'))->first();
    }

    /**
     * Checks the request header for sandbox headers if to set and switch to the sandbox database,
     * or uses the `ApiCredential` provided to set sandbox session.
     *
     * @param \Illuminate\Http\Request        $request
     * @param \Fleetbase\Models\ApiCredential $apiCredential
     *
     * @return bool
     */
    public static function setSandboxSession($request, $apiCredential = null)
    {
        $isSandbox       = $request->header('Access-Console-Sandbox') ?? Utils::get($apiCredential, 'test_mode', false);
        $apiCredentialId = $request->header('Access-Console-Sandbox-Key') ?? Utils::get($apiCredential, 'uuid', false);
        $sandboxSession  = [];

        // if is sandbox environment switch to the sandbox database
        if ($isSandbox) {
            config(['database.default' => 'sandbox']);
            $sandboxSession['is_sandbox'] = (bool) $isSandbox;

            if ($apiCredentialId) {
                $sandboxSession['sandbox_api_credential'] = $apiCredentialId;
            }
        }

        session($sandboxSession);

        return true;
    }

    /**
     * Get the session company.
     */
    public static function getCompany($select = '*'): ?Company
    {
        if (!session('company')) {
            return null;
        }

        return Company::select($select)->where('uuid', session('company'))->first();
    }

    /**
     * Verifies a password against a hash.
     */
    public static function checkPassword(string $pw1, string $pw2): bool
    {
        return Hash::check($pw1, $pw2);
    }

    /**
     * Checks if password is invalid.
     */
    public static function isInvalidPassword(string $pw1, string $pw2): bool
    {
        return !static::checkPassword($pw1, $pw2);
    }
}
