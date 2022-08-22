<?php

namespace Fleetbase\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class Http
{
    public static function isInternalRequest(Request $request)
    {
        $controllerClassName = get_class($request->route()->getController());
        return Str::startsWith($controllerClassName, (Str::startsWith($controllerClassName, '\\') ? '\\' : '') . 'Fleetbase\\Http\\Controllers\\Internal\\');
    }

    /**
     * Parses the sort request parameter and returns the sort param and direction of sort.
     *
     * @param mixed $sort
     * @return array
     */
    public static function useSort($sort): array
    {
        if ($sort instanceof Request) {
            $sort = $sort->input('sort');
        }

        if (is_array($sort)) {
            return $sort;
        }

        $param = $sort;
        $direction = 'desc';

        if (Str::startsWith($sort, '-')) {
            $direction = Str::startsWith($sort, '-') ? 'desc' : 'asc';
            $param = Str::startsWith($sort, '-') ? substr($sort, 1) : $sort;
        } else {
            $sd = explode(":", $sort);

            if ($sd && count($sd) > 0) {
                $direction = $sd[1] ?? $direction;
                $param = $sd[0];
            } else {
                $param = $sort;
            }
        }

        return [$param, $direction];
    }

    /**
     * Looks up a user client info w/ api
     *
     * @param string $ip
     * @return stdClass
     */
    public static function lookupIp($ip = null)
    {
        if ($ip instanceof Request) {
            $ip = $ip->ip();
        }
        
        if ($ip === null) {
            $ip = request()->ip();
        }

        $curl = new \Curl\Curl();
        $curl->get('https://api.ipdata.co/' . $ip, ['api-key' => config('fleetbase.services.ipinfo.api_key')]);

        return $curl->response;
    }
}
