<?php

namespace Fleetbase\Exceptions;

use Fleetbase\Support\Auth;
use Illuminate\Http\Request;

class UnauthorizedRequestException extends \Exception implements \Throwable
{
    protected array $errors   = [];

    public function __construct(Request $request, $code = 0, ?\Throwable $previous = null)
    {
        $message      = $this->getErrorMessage($request);
        $this->errors = [$message];
        parent::__construct($message, $code, $previous);
    }

    public function getErrorMessage(Request $request): string
    {
        try {
            $requiredPermission = Auth::getRequiredPermissionNameFromRequest($request);
        } catch (\Throwable) {
            return 'Unauthorized Request';
        }

        if (!$requiredPermission) {
            return 'Unauthorized Request';
        }

        return 'User is not authorized to ' . $requiredPermission;
    }
}
