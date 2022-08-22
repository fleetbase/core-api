<?php

namespace Fleetbase\Expansions;

use Fleetbase\Build\Expansion;

class Response implements Expansion
{
    /**
     * Get the target class to expand.
     *
     * @return string|Class
     */
    public static function target()
    {
        return \Illuminate\Support\Facades\Response::class;
    }

    /**
     * Iterates request params until a param is found.
     *
     * @return Closure
     */
    public function error()
    {
        /**
         * Returns an error response.
         *
         * @param array $params
         * @param mixed $default
         * @return mixed
         */
        return function ($error, int $statusCode = 400, ?array $data = []) {
            return static::json(
                [
                    'errors' => is_array($error) ? $error : [$error],
                    ...$data
                ],
                $statusCode
            );
        };
    }
}
