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
        return TwoFactorAuth::saveSettings($request);
    }

    /**
     * Get Two-Factor Authentication settings.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getSettings()
    {
        return TwoFactorAuth::getSettings();
    }

    /**
     * Verify Two-Factor Authentication code.
     *
     * @param \Fleetbase\Http\Requests\TwoFaValidationRequest $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function validateSession(TwoFaValidationRequest $request)
    {
        return TwoFactorAuth::validateSession($request);
    }

    /**
     * Check Two-Factor Authentication status for a given user identity.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function checkTwoFactor(Request $request)
    {
        $result = TwoFactorAuth::checkTwoFactorStatus($request);
    
    return response()->json($result);
    }

    /**
     * Verify Two-Factor Authentication code.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyCode(Request $request) {
        return TwoFactorAuth::verifyCode($request);
    }
    
}
