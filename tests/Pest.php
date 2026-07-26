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

namespace Illuminate\Foundation\Events {
    if (!trait_exists(Dispatchable::class)) {
        trait Dispatchable
        {
        }
    }
}

namespace Illuminate\Foundation\Http {
    if (!class_exists(FormRequest::class)) {
        class FormRequest extends \Illuminate\Http\Request
        {
            public function setContainer($container): static
            {
                return $this;
            }

            public function setRedirector($redirector): static
            {
                return $this;
            }
        }
    }
}

namespace Illuminate\Foundation\Auth {
    if (!class_exists(User::class)) {
        class User extends \Illuminate\Database\Eloquent\Model
        {
        }
    }
}

namespace PhpOption {
    if (!class_exists(Option::class)) {
        class Option
        {
            public function __construct(private mixed $value)
            {
            }

            public static function fromValue(mixed $value): self
            {
                return new self($value);
            }

            public function map(callable $callback): self
            {
                if ($this->value === null) {
                    return $this;
                }

                return new self($callback($this->value));
            }

            public function getOrCall(callable $callback): mixed
            {
                return $this->value ?? $callback();
            }

            public function getOrThrow(\Throwable $throwable): mixed
            {
                if ($this->value === null) {
                    throw $throwable;
                }

                return $this->value;
            }
        }
    }
}

namespace Dotenv\Repository {
    if (!class_exists(RepositoryBuilder::class)) {
        class RepositoryBuilder
        {
            public static function createWithDefaultAdapters(): self
            {
                return new self();
            }

            public function addAdapter(string $adapter): self
            {
                return $this;
            }

            public function immutable(): self
            {
                return $this;
            }

            public function make(): object
            {
                return new class {
                    public function get(string $key): mixed
                    {
                        return null;
                    }
                };
            }
        }
    }
}

namespace Dotenv\Repository\Adapter {
    if (!class_exists(PutenvAdapter::class)) {
        class PutenvAdapter
        {
        }
    }
}

namespace Fleetbase\Support {
    if (!function_exists(__NAMESPACE__ . '\\env')) {
        function env(string $key, mixed $default = null): mixed
        {
            $value = getenv($key);

            return $value === false ? (is_callable($default) ? $default() : $default) : $value;
        }
    }
}

namespace Fleetbase\Models {
    if (!function_exists(__NAMESPACE__ . '\\asset')) {
        function asset(string $path = '', ?bool $secure = null): string
        {
            $base = $secure ? 'https://fleetbase.test' : 'http://fleetbase.test';

            return $base . '/' . ltrim($path, '/');
        }
    }
}

namespace Fleetbase\Observers {
    if (!function_exists(__NAMESPACE__ . '\\event')) {
        function event(object|string $event, mixed $payload = [], bool $halt = false): mixed
        {
            if (function_exists('\\event')) {
                return \event($event, $payload, $halt);
            }

            return null;
        }
    }
}

namespace {
    use Illuminate\Container\Container;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Facades\Facade;

    if (!function_exists('config')) {
        function config($key = null, $default = null)
        {
            $config = Container::getInstance()->make('config');

            if ($key === null) {
                return $config;
            }

            if (is_array($key)) {
                foreach ($key as $name => $value) {
                    $config->set($name, $value);
                }

                return true;
            }

            return $config->get($key, $default);
        }
    }

    if (!function_exists('app')) {
        function app($abstract = null, array $parameters = [])
        {
            $container = Container::getInstance();

            if ($abstract === null) {
                return $container;
            }

            return $container->make($abstract, $parameters);
        }
    }

    if (!function_exists('request')) {
        function request($key = null, $default = null)
        {
            $request = Container::getInstance()->bound('request')
                ? Container::getInstance()->make('request')
                : Request::create('/');

            return $key === null ? $request : $request->input($key, $default);
        }
    }

    if (!function_exists('cache')) {
        function cache(mixed $key = null, mixed $default = null): mixed
        {
            $cache = app('cache');

            if ($key === null) {
                return $cache;
            }

            return $cache->get($key, $default);
        }
    }

    if (!function_exists('session')) {
        function session($key = null, $default = null)
        {
            static $store = [];

            if (is_array($key)) {
                $store = array_merge($store, $key);

                return true;
            }

            if ($key === null) {
                return new class($store) {
                    public function __construct(private array &$store)
                    {
                    }

                    public function has(string $key): bool
                    {
                        return array_key_exists($key, $this->store);
                    }

                    public function missing(string $key): bool
                    {
                        return !$this->has($key);
                    }

                    public function get(string $key, mixed $default = null): mixed
                    {
                        return $this->store[$key] ?? $default;
                    }

                    public function put(string $key, mixed $value): void
                    {
                        $this->store[$key] = $value;
                    }

                    public function remove(string $key): mixed
                    {
                        $value = $this->store[$key] ?? null;
                        unset($this->store[$key]);

                        return $value;
                    }

                    public function flush(): void
                    {
                        $this->store = [];
                    }
                };
            }

            return $store[$key] ?? $default;
        }
    }

    if (!function_exists('now')) {
        function now($tz = null): Carbon
        {
            return Carbon::now($tz);
        }
    }

    if (!function_exists('storage_path')) {
        function storage_path(string $path = ''): string
        {
            $base = sys_get_temp_dir() . '/fleetbase-core-api-test-storage';

            return $path ? $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : $base;
        }
    }

    if (!function_exists('url')) {
        function url(string $path = '', mixed $parameters = [], ?bool $secure = null): string
        {
            $base = $secure ? 'https://fleetbase.test' : 'http://fleetbase.test';
            $path = '/' . ltrim($path, '/');

            if (is_array($parameters) && $parameters !== []) {
                return $base . $path . '?' . http_build_query($parameters);
            }

            return $base . $path;
        }
    }

    if (!function_exists('route')) {
        function route(string $name, array $parameters = []): string
        {
            $path = str_replace('.', '/', $name);

            if ($parameters !== []) {
                $path .= '/' . implode('/', array_map('rawurlencode', array_values($parameters)));
            }

            return url($path);
        }
    }

    if (!function_exists('auth')) {
        function auth()
        {
            return new class {
                public function id(): ?string
                {
                    return session('user');
                }

                public function user(): mixed
                {
                    return session('user');
                }

                public function check(): bool
                {
                    return session()->has('user');
                }
            };
        }
    }

    class FleetbaseTestContainer extends Container
    {
        public function environment(array|string|null $environments = null): bool|string
        {
            $current = $this->bound('config')
                ? $this->make('config')->get('app.env', 'testing')
                : 'testing';

            if ($environments === null) {
                return $current;
            }

            if (is_array($environments)) {
                return in_array($current, $environments, true);
            }

            return $current === $environments;
        }

        public function hasDebugModeEnabled(): bool
        {
            return (bool) ($this->bound('config')
                ? $this->make('config')->get('app.debug', false)
                : false);
        }

        public function isProduction(): bool
        {
            return $this->environment('production');
        }

        public function runningInConsole(): bool
        {
            return true;
        }

        public function runningUnitTests(): bool
        {
            return true;
        }
    }

    function bind_test_container(array $config = []): Container
    {
        if (!Container::getInstance() instanceof FleetbaseTestContainer) {
            Container::setInstance(new FleetbaseTestContainer());
        }

        $container = Container::getInstance();
        Facade::setFacadeApplication($container);

        if (!$container->bound('config')) {
            $container->instance('config', new Illuminate\Config\Repository());
        }

        foreach ($config as $key => $value) {
            $container->make('config')->set($key, $value);
        }

        $container->instance('request', Request::create('/int/v1/test', 'GET'));

        $container->instance('log', new class {
            public array $entries = [];

            public function debug(string $message, array $context = []): void
            {
                $this->entries[] = ['debug', $message, $context];
            }

            public function info(string $message, array $context = []): void
            {
                $this->entries[] = ['info', $message, $context];
            }

            public function error(string $message, array $context = []): void
            {
                $this->entries[] = ['error', $message, $context];
            }

            public function warning(string $message, array $context = []): void
            {
                $this->entries[] = ['warning', $message, $context];
            }
        });

        $container->instance('cache', new class {
            private array $values = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->values[$key] ?? $default;
            }

            public function put(string $key, mixed $value, mixed $ttl = null): bool
            {
                $this->values[$key] = $value;

                return true;
            }

            public function forget(string $key): bool
            {
                unset($this->values[$key]);

                return true;
            }
        });

        return $container;
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

        public function apiError($error, int $statusCode = 400, ?array $data = []): JsonResponse
        {
            return $this->json(
                [
                    'error' => $error,
                    ...$data,
                ],
                $statusCode
            );
        }

        public function authorizationError(?array $data = []): JsonResponse
        {
            return $this->json(
                array_merge([
                    'errors' => ['User is not authorized to create api-key'],
                ], $data),
                401
            );
        }

        public function json(mixed $data, int $statusCode = 200): JsonResponse
        {
            return new JsonResponse($data, $statusCode);
        }

        public function download(string $file, ?string $name = null, array $headers = []): Symfony\Component\HttpFoundation\BinaryFileResponse
        {
            $response = new Symfony\Component\HttpFoundation\BinaryFileResponse($file, 200, $headers, true, null, false, false);

            if ($name !== null) {
                $response->setContentDisposition(Symfony\Component\HttpFoundation\ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name);
            }

            return $response;
        }
    }
}
