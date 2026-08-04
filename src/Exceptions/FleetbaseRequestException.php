<?php

namespace Fleetbase\Exceptions;

class FleetbaseRequestException extends \Exception implements \Throwable
{
    protected array $errors   = [];

    public function __construct($errors = [], $message = 'Invalid request', $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errors = (array) $errors;
    }

    public function getErrors()
    {
        return (array) $this->errors;
    }
}
