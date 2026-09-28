<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Requests\TwoFaValidationRequest;
use Fleetbase\Support\Auth;
use Fleetbase\Support\TwoFactorAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Class TwoFaController.
 */
class TwoFaController extends Controller
{
    /**
     * Save Two-Factor Authentication system wide settings.
     *
     * @return \Illuminate\Http\Response
     */
    public function saveSystemConfig(Request $request)
    {
        $twoFaSettings = $request->array('twoFaSettings');
        if (isset($twoFaSettings['enabled']) && $twoFaSettings['enabled'] === false) {
            $twoFaSettings['enforced'] = false;
        }
        $settings      = TwoFactorAuth::configureTwoFaSettings($twoFaSettings);

        return response()->json($settings->value);
    }

    /**
     * Get Two-Factor Authentication system wide settings.
     *
     * @return \Illuminate\Http\Response
     */
    public function getSystemConfig()
    {
        $settings = TwoFactorAuth::getTwoFaConfiguration();

        return response()->json($settings->value);
    }

    /**
     * Retained for older consoles, which call this before submitting the password.
     *
     * It used to start a 2FA session from the identity alone, which let the emailed/SMS
     * code stand in for the password and revealed which accounts have 2FA enabled. A 2FA
     * session is now only started by `auth/login` once the password checks out, so this
     * always reports 2FA as off and older consoles continue to the password login.
     *
     * @return \Illuminate\Http\Response
     */
    public function checkTwoFactor(Request $request)
    {
        return response()->json([
            'twoFaSession'   => null,
            'isTwoFaEnabled' => false,
        ]);
    }

    /**
     * Verify Two-Factor Authentication code.
     *
     * @return \Illuminate\Http\Response
     */
    public function validateSession(TwoFaValidationRequest $request)
    {
        $token       = $request->input('token');
        $identity    = $request->input('identity');
        $clientToken = $request->input('clientToken');

        try {
            $validClientToken = TwoFactorAuth::getClientSessionTokenFromTwoFaSession($token, $identity, $clientToken);

            return response()->json([
                'clientToken' => $validClientToken,
                'expired'     => false,
            ]);
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();

            if (Str::contains($errorMessage, ['2FA Verification', 'expired'])) {
                return response()->json([
                    'expired' => true,
                ]);
            }

            return response()->error($errorMessage);
        }
    }

    /**
     * Verify Two-Factor Authentication code.
     *
     * @return \Illuminate\Http\Response
     */
    public function verifyCode(Request $request)
    {
        $code        = $request->input('code');
        $token       = $request->input('token');
        $clientToken = $request->input('clientToken');

        try {
            $authToken = TwoFactorAuth::verifyCode($code, $token, $clientToken);

            // Driver and contact accounts cannot sign in to the console. Customers
            // are left to the customer portal, which shares this route path.
            $accessToken = PersonalAccessToken::findToken($authToken);
            if ($denied = Auth::denyConsoleSession($accessToken?->tokenable)) {
                $accessToken->delete();

                return $denied;
            }

            return response()->json([
                'authToken' => $authToken,
            ]);
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        }
    }

    /**
     * Resend Two-Factor Authentication verification code.
     *
     * @return \Illuminate\Http\Response
     */
    public function resendCode(Request $request)
    {
        $identity = $request->input('identity');
        $token    = $request->input('token');

        try {
            $clientToken = TwoFactorAuth::resendCode($identity, $token);

            return response()->json([
                'clientToken' => $clientToken,
            ]);
        } catch (\Exception $e) {
            return response()->error($e->getMessage());
        }
    }

    /**
     * Invalidate the current two-factor session.
     *
     * @return \Illuminate\Http\Response
     */
    public function invalidateSession(Request $request)
    {
        $identity = $request->input('identity');
        $token    = $request->input('token');

        try {
            $ok = TwoFactorAuth::forgetTwoFaSession($token, $identity);

            return response()->json([
                'ok' => $ok,
            ]);
        } catch (\Exception $e) {
            return response()->json(['ok' => false]);
        }
    }

    public function shouldEnforce(Request $request)
    {
        $user         = $request->user();
        $enforceTwoFa = TwoFactorAuth::shouldEnforce($user);

        return response()->json([
            'shouldEnforce' => $enforceTwoFa,
        ]);
    }
}
