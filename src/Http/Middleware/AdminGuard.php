<?php

namespace Fleetbase\Http\Middleware;

use Fleetbase\Support\Auth;
use Illuminate\Http\Request;

class AdminGuard
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, \Closure $next)
    {
        if (auth()->check()) {
            /** @var \Fleetbase\Models\User $user */
            $user = Auth::getUserFromSession($request);

            if ($user->isAdmin()) {
                return $next($request);
            }
        }

        return response()->error('User is not authorized to access this resource.', 401);
    }
}
