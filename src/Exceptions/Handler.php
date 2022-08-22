<?php

namespace Fleetbase\Exceptions;

use Exception;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Fleetbase\Support\Utils;
use Fleetbase\Support\Resp;
use Fleetbase\Exceptions\IntegratedVendorException;
use Throwable;

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
        return Resp::error('Unauthenticated.', 401);
    }

    /**
     * Report or log an exception.
     *
     * @param  \Throwable  $exception
     * @return void
     *
     * @throws \Exception
     */
    public function report(Throwable $exception)
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
    public function render($request, Throwable $exception)
    {
        $exceptionType = Utils::getClassName($exception);

        switch ($exceptionType) {
            case 'TokenMismatchException':
                return Resp::error('Invalid XSRF token sent with request.');

            case 'ThrottleRequestsException':
                return Resp::error('Too many requests.');

            case 'AuthenticationException':
                return Resp::error('Unauthenticated.');

            case 'NotFoundHttpException':
                return Resp::error('There is nothing to see here.');

            case 'IntegratedVendorException':
                return Resp::error($exception->getMessage());
        }

        if (app()->environment(['development', 'local'])) {
            return parent::render($request, $exception);
        }

        Resp::error('Oops! A backend error has been reported, please try your request again to continue.', 400);
    }
}
