<?php

namespace Fleetbase\Support;

use Fleetbase\Models\VerificationCode;
use Aloha\Twilio\Support\Laravel\Facade as Twilio;
use Fleetbase\Models\Setting;
use Fleetbase\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Fleetbase\Support\Utils;
use Illuminate\Http\Request;

/**
 * Class TwoFactorAuth
 *
 * @package Fleetbase\Support
 */
class TwoFactorAuth
{
    /**
     * Save Two-Factor Authentication settings.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     * @throws \Exception
     */
    public static function saveSettings($request)
    {
        $twoFaSettings = $request->input('twoFaSettings');
        if (!is_array($twoFaSettings)) {
            throw new \Exception('Invalid 2FA settings data.');
        }
        Setting::configure('2fa', $twoFaSettings);

        return response()->json([
            'status'  => 'ok',
            'message' => '2Fa settings successfully saved.',
        ]);
    }

    /**
     * Get Two-Factor Authentication settings.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public static function getSettings()
    {
        $twoFaSettings = Setting::lookup('2fa', ['enabled' => false, 'method' => 'authenticator_app']);

        return response()->json($twoFaSettings);
    }

    /**
     * Verify Two-Factor Authentication code.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public static function validateSession($request)
    {
        try {
            $request->validate([
                'token' => 'required',
            ]);

            if (!self::isEnabled()) {
                return response()->error('Two Factor Authentication is not enabled.', 400);
            }

            $identity = $request->input('identity');

            $user = User::where(function ($query) use ($identity) {
                $query->where('email', $identity)->orWhere('phone', $identity);
            })->first();

            if (!$user) {
                return response()->error('No user found by this phone number.', 401);
            }

            VerificationCode::generateSmsVerificationFor($user);

            return response()->json(['status' => 'ok']);
        } catch (ValidationException $e) {
            return response()->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            return response()->error($e->getMessage(), 500);
        }
    }

    /**
     * Check Two-Factor Authentication status for a given user identity.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public static function checkTwoFactorStatus(Request $request)
    {
        $identity = $request->input('identity');
        $isTwoFaEnabled = self::isEnabled();
        $twoFaSession = null;
        $isTwoFaValidated = false;
        $error = null;

        if ($isTwoFaEnabled) {
            $twoFaSession = self::start($identity);

            if ($twoFaSession === null) {
                $error = 'No user found using the provided identity';
            } else {
                $isTwoFaValidated = self::isTwoFactorSessionValidated($twoFaSession);
            }
        }

        return [
            'isTwoFaEnabled' => $isTwoFaEnabled,
            'isTwoFaValidated' => $isTwoFaValidated,
            'twoFaSession' => $twoFaSession,
            'error' => $error
        ];
    }

    /**
     * Check if Two-Factor Authentication is enabled.
     *
     * @return bool
     */
    public static function isEnabled()
    {
        $twoFaSettings = Setting::lookup('2fa');

        return isset($twoFaSettings['enabled']) ? (bool)$twoFaSettings['enabled'] : false;
    }

    /**
     * Start the Two-Factor Authentication process and return the session key.
     *
     * @return string
     */
    public static function start(string $identity): ?string
    {
        $twoFaSession = Str::random(40);

        $user = User::where(function ($query) use ($identity) {
            $query->where('email', $identity)->orWhere('phone', $identity);
        })->first();

        if ($user) {
            Cache::put('two_fa_session:' . $user->uuid, true, now()->addMinutes(10));
            return $twoFaSession;
        }

        return null;
    }

    /**
     * Check if the Two-Factor Authentication session is validated.
     *
     * @param string|null $twoFaSession - The Two-Factor Authentication session key
     * @return bool - True if the session is validated, false otherwise
     */
    public static function isTwoFactorSessionValidated(?string $twoFaSession = null): bool
    {
        if ($twoFaSession === null) {
            return false;
        }
        // do check here
        return false;
    }
    
}