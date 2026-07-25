<?php

namespace Illuminate\Foundation\Exceptions {
    if (!class_exists(Handler::class)) {
        class Handler
        {
            public function __construct(protected mixed $container = null)
            {
            }

            public function render($request, \Throwable $exception)
            {
                throw $exception;
            }

            public function report(\Throwable $exception)
            {
            }

            protected function reportable(callable $callback): void
            {
            }
        }
    }
}

namespace Fleetbase\Exceptions {
    if (!function_exists(__NAMESPACE__ . '\logger')) {
        function logger()
        {
            return app('log');
        }
    }
}

namespace {
    use Fleetbase\Exceptions\FleetbaseRequestValidationException;
    use Fleetbase\Exceptions\Handler;
    use Fleetbase\Models\User;
    use Illuminate\Auth\AuthenticationException;
    use Illuminate\Database\Eloquent\ModelNotFoundException;
    use Illuminate\Http\Exceptions\ThrottleRequestsException;
    use Illuminate\Http\Request;
    use Illuminate\Session\TokenMismatchException;
    use Illuminate\Support\Facades\Facade;
    use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

    class TestableExceptionHandler extends Handler
    {
        public array $reportableCallbacks = [];

        public function exposeUnauthenticated(Request $request, AuthenticationException $exception)
        {
            return $this->unauthenticated($request, $exception);
        }

        protected function reportable(callable $callback): void
        {
            $this->reportableCallbacks[] = $callback;
        }
    }

    function exception_handler_subject(): Handler
    {
        bind_test_container();
        Facade::clearResolvedInstances();

        return new Handler(app());
    }

    afterEach(function () {
        Facade::clearResolvedInstances();
    });

    it('returns stable json error contracts for manually handled framework exceptions', function (Throwable $exception, array $expectedErrors, int $expectedStatus) {
        $handler = exception_handler_subject();
        $request = Request::create('/int/v1/reports', 'GET');

        $response = $handler->render($request, $exception);

        expect($response->getStatusCode())->toBe($expectedStatus)
            ->and($response->getData(true))->toBe([
                'errors' => $expectedErrors,
            ]);
    })->with([
        'token mismatch'         => [new TokenMismatchException(), ['Invalid XSRF token sent with request.'], 400],
        'throttled request'      => [new ThrottleRequestsException('Slow down'), ['Too many requests.'], 400],
        'authentication failure' => [new AuthenticationException(), ['Unauthenticated.'], 400],
        'http not found'         => [new NotFoundHttpException(), ['There is nothing to see here.'], 400],
    ]);

    it('returns a resource-specific model not found json response when the model is known', function () {
        $handler   = exception_handler_subject();
        $exception = (new ModelNotFoundException())->setModel(User::class);

        $response = $handler->render(Request::create('/int/v1/users/user-1', 'GET'), $exception);

        expect($response->getStatusCode())->toBe(404)
            ->and($response->getData(true))->toBe([
                'errors' => ['User not found.'],
            ]);
    });

    it('returns a generic model not found json response when the missing model is unknown', function () {
        $handler = exception_handler_subject();

        $response = $handler->render(Request::create('/int/v1/unknown', 'GET'), new ModelNotFoundException());

        expect($response->getStatusCode())->toBe(404)
            ->and($response->getData(true))->toBe([
                'errors' => ['Requested resource not found.'],
            ]);
    });

    it('returns validation errors from Fleetbase request validation exceptions', function () {
        $handler = exception_handler_subject();

        $response = $handler->render(
            Request::create('/int/v1/reports/validate-query', 'POST'),
            new FleetbaseRequestValidationException(['columns.0 is required', 'filters must be an array'])
        );

        expect($response->getStatusCode())->toBe(400)
            ->and($response->getData(true))->toBe([
                'errors' => ['columns.0 is required', 'filters must be an array'],
            ]);
    });

    it('registers a reportable exception callback for external monitoring', function () {
        bind_test_container();
        Facade::clearResolvedInstances();

        $handler   = new TestableExceptionHandler(app());
        $exception = new RuntimeException('Report me');

        $handler->register();
        ($handler->reportableCallbacks[0])($exception);

        expect($handler->reportableCallbacks)->toHaveCount(1)
            ->and($handler->reportableCallbacks[0])->toBeCallable();
    });

    it('returns the stable unauthenticated json response from the framework hook', function () {
        bind_test_container();
        Facade::clearResolvedInstances();

        $handler = new TestableExceptionHandler(app());

        $response = $handler->exposeUnauthenticated(
            Request::create('/int/v1/users', 'GET'),
            new AuthenticationException()
        );

        expect($response->getStatusCode())->toBe(401)
            ->and($response->getData(true))->toBe([
                'errors' => ['Unauthenticated.'],
            ]);
    });

    it('logs cloudwatch-safe exception payloads before delegating reports', function () {
        $handler   = exception_handler_subject();
        $exception = new RuntimeException('Webhook failed', 500);

        $handler->report($exception);

        $entries = app('log')->entries;
        $payload = json_decode($entries[0][1], true);

        expect($entries)->toHaveCount(1)
            ->and($entries[0][0])->toBe('error')
            ->and($payload)->toMatchArray([
                'message' => 'Webhook failed',
                'code'    => 500,
                'file'    => $exception->getFile(),
                'line'    => $exception->getLine(),
            ]);
    });

    it('delegates unknown exceptions to the framework renderer', function () {
        $handler   = exception_handler_subject();
        $exception = new RuntimeException('Unexpected failure');

        expect(fn () => $handler->render(Request::create('/int/v1/test', 'GET'), $exception))
            ->toThrow(RuntimeException::class, 'Unexpected failure');
    });

    it('formats exceptions for CloudWatch without leaking the full throwable object', function () {
        $handler   = exception_handler_subject();
        $exception = new RuntimeException('Export failed', 503);

        $payload = json_decode($handler->getCloudwatchLoggableException($exception), true);

        expect($payload)->toMatchArray([
            'message' => 'Export failed',
            'code'    => 503,
            'file'    => $exception->getFile(),
            'line'    => $exception->getLine(),
        ]);
    });

    it('falls back to exception messages when CloudWatch json encoding cannot represent the message', function () {
        $handler   = exception_handler_subject();
        $exception = new RuntimeException("\xB1\x31");

        expect($handler->getCloudwatchLoggableException($exception))->toBe("\xB1\x31");
    });

    it('keeps a default manual error response for explicitly invoked fallback handling', function () {
        $handler = exception_handler_subject();
        $method  = new ReflectionMethod($handler, 'manuallyHandleException');
        $method->setAccessible(true);

        $response = $method->invoke($handler, new RuntimeException('Manually handled failure'));

        expect($response->getStatusCode())->toBe(400)
            ->and($response->getData(true))->toBe([
                'errors' => ['Manually handled failure'],
            ]);
    });
}
