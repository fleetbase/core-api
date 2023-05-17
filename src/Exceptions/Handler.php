<?php

namespace Fleetbase\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Log;
use Fleetbase\Support\Utils;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = ['password', 'password_confirmation'];

    /**
     * Handles unauthenticated request esxceptions.
     *
     * @param \Iluminate\Http\Request $request
     * @param AuthenticationException $exception
     * @return void
     */
    protected function unauthenticated($request, AuthenticationException $exception)
    {
        return response()->error('Unauthenticated.', 401);
    }

    /**
     * Report or log an exception.
     *
     * @param  \Throwable  $exception
     * @return void
     *
     * @throws \Exception
     */
    public function report(\Throwable $exception)
    {
        // log to Sentry
        if ($this->shouldReport($exception) && app()->bound('sentry')) {
            app('sentry')->captureException($exception);
        }

        // log to CloudWatch
        Log::error($exception->getMessage() ?? class_basename($exception));

        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $exception
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Throwable
     */
    public function render($request, \Throwable $exception)
    {
        $exceptionType = Utils::classBasename($exception);

        switch ($exceptionType) {
            case 'TokenMismatchException':
                return response()->error('Invalid XSRF token sent with request.');

            case 'ThrottleRequestsException':
                return response()->error('Too many requests.');

            case 'AuthenticationException':
                return response()->error('Unauthenticated.');

            case 'NotFoundHttpException':
                return response()->error('There is nothing to see here.');

            case 'IntegratedVendorException':
                return response()->error($exception->getMessage());
        }

        if (app()->environment(['development', 'local'])) {
            return parent::render($request, $exception);
        }

        return response()->error('Oops! A backend error has been reported, please try your request again to continue.', 400);
    }
}
