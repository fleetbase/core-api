<?php

namespace Fleetbase\Expansions;

use CompressJson\Core\Compressor;
use Fleetbase\Build\Expansion;
use Illuminate\Support\MessageBag;

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
        /*
         * Returns an error response.
         *
         * @param array $params
         * @param mixed $default
         * @return mixed
         */
        return function ($error, int $statusCode = 400, ?array $data = []) {
            if ($error instanceof MessageBag) {
                $error = $error->all();
            }

            /* @var \Illuminate\Support\Facades\Response $this */
            return static::json(
                array_merge([
                    'errors' => is_array($error) ? $error : [$error],
                ], $data),
                $statusCode
            );
        };
    }

    /**
     * Formats a error response for the consumable API.
     *
     * @return Closure
     */
    public function apiError()
    {
        /*
         * Returns an error response.
         *
         * @param array $params
         * @param mixed $default
         * @return mixed
         */
        return function ($error, int $statusCode = 400, ?array $data = []) {
            if ($error instanceof MessageBag) {
                $error = $error->all();
            }

            /* @var \Illuminate\Support\Facades\Response $this */
            return static::json(
                [
                    'error' => $error,
                    ...$data,
                ],
                $statusCode
            );
        };
    }

    /**
     * Creates a compressed json response using json compression library.
     *
     * @return Closure
     */
    public function compressedJson()
    {
        return function ($data = [], $status = 200, array $headers = [], int $options = 0) {
            /**
             * Context.
             *
             * @var \Illuminate\Support\Facades\Response $this
             */
            // Serialize data to JSON
            $jsonData = json_encode($data, $options);

            // Compress JSON data
            $compressedData = Compressor::create()->compress($jsonData)->toArray();

            // Set headers for compressed response
            $headers = array_merge([
                'X-Compressed-Json' => '1',
            ], $headers);

            return $this->json($compressedData, $status, $headers, $options);
        };
    }
}
