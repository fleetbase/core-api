<?php

namespace Fleetbase\Support;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class Http {
    public static function isInternalRequest(Request $request)
    {
        $controllerClassName = get_class($request->route()->getController());
        return Str::startsWith($controllerClassName, (Str::startsWith($controllerClassName, '\\') ? '\\' : '') . 'Fleetbase\\Http\\Controllers\\Internal\\');
    }

    public static function getResourceMetaFromPaginator(LengthAwarePaginator $paginator)
    {
        $page = $paginator->currentPage();

        return [
            'total' => $paginator->total(),
            'per_page' => (int) $paginator->perPage(),
            'current_page' => $page,
            'last_page' => $paginator->lastPage(),
            'path' => $paginator->url($page),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
            'time' => microtime(true) - LARAVEL_START
        ];
    }
}