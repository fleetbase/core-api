<?php

namespace Fleetbase\Http\Controllers\Internal\v1;

use Fleetbase\Http\Controllers\Controller;
use Fleetbase\Http\Requests\TwoFaValidationRequest;
use Illuminate\Http\Request;
use Fleetbase\Support\TwoFactorAuth;

/**
 * Class TwoFaController
 *
 * @package Fleetbase\Http\Controllers\Internal\v1
 */
class TwoFaController extends Controller
{
    /**
     * TwoFactorAuth instance.
     *
     * @var \Fleetbase\Support\TwoFactorAuth
     */
    protected $twoFactorAuth;

    /**
     * TwoFaController constructor.
     *
     * @param \Fleetbase\Support\TwoFactorAuth $twoFactorAuth
     */
    public function __construct(TwoFactorAuth $twoFactorAuth)
    {
        $this->twoFactorAuth = $twoFactorAuth;
    }

    /**
     * Save Two-Factor Authentication settings.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveSettings(Request $request)
    {
        try {
            $result = $this->twoFactorAuth->saveSettings($request);
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Get Two-Factor Authentication settings.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSettings()
    {
        try {
            $result = $this->twoFactorAuth->getSettings();
            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Verify Two-Factor Authentication code.
     *
     * @param \Fleetbase\Http\Requests\TwoFaValidationRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function validateSession(TwoFaValidationRequest $request)
    {
        try {
            $clientSessionToken = $this->twoFactorAuth->validateSession($request);

            return response()->json([
                'status' => 'ok',
                'clientToken' => $clientSessionToken,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Check Two-Factor Authentication status for a given user identity.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkTwoFactor(Request $request)
    {
        try {
            $result = $this->twoFactorAuth->checkTwoFactorStatus($request);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Verify Two-Factor Authentication code.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyCode(Request $request)
    {
        try {
            $authToken = $this->twoFactorAuth->verifyCode($request);

            return response()->json([
                'authToken' => $authToken
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Resend Two-Factor Authentication verification code.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resendCode(Request $request)
    {
        try {
            $clientSessionToken = $this->twoFactorAuth->resendCode($request);
            
            return response()->json([
                'status' => 'ok',
                'clientToken' => $clientSessionToken,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }
}
