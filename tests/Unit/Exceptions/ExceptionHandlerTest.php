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
}
