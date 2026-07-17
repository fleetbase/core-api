<?php

namespace Illuminate\Foundation\Auth\Access {
    if (!trait_exists(AuthorizesRequests::class)) {
        trait AuthorizesRequests
        {
        }
    }
}

namespace Illuminate\Foundation\Bus {
    if (!trait_exists(DispatchesJobs::class)) {
        trait DispatchesJobs
        {
        }
    }
}

namespace Illuminate\Foundation\Validation {
    if (!trait_exists(ValidatesRequests::class)) {
        trait ValidatesRequests
        {
        }
    }
}

namespace Illuminate\Foundation\Http {
    if (!class_exists(FormRequest::class)) {
        class FormRequest extends \Illuminate\Http\Request
        {
        }
    }
}

namespace {
    use Illuminate\Container\Container;
    use Illuminate\Http\JsonResponse;

    if (!function_exists('config')) {
        function config($key = null, $default = null)
        {
            $config = Container::getInstance()->make('config');

            if ($key === null) {
                return $config;
            }

            return $config->get($key, $default);
        }
    }

    if (!function_exists('response')) {
        function response(): AcceptCompanyInviteTestResponseFactory
        {
            return new AcceptCompanyInviteTestResponseFactory();
        }
    }

    class AcceptCompanyInviteTestResponseFactory
    {
        public function error($error, int $statusCode = 400, ?array $data = []): JsonResponse
        {
            return $this->json(
                array_merge([
                    'errors' => is_array($error) ? $error : [$error],
                ], $data),
                $statusCode
            );
        }

        public function json(array $data, int $statusCode = 200): JsonResponse
        {
            return new JsonResponse($data, $statusCode);
        }
    }
}
