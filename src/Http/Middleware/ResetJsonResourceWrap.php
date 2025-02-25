<?php

namespace Fleetbase\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResetJsonResourceWrap
{
    public function handle(Request $request, \Closure $next)
    {
        JsonResource::withoutWrapping();

        return $next($request);
    }
}
