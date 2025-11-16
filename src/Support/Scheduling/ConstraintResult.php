<?php

namespace Fleetbase\Support\Scheduling;

class ConstraintResult
{
    protected $passed;
    protected $violations;

    public function __construct(bool $passed, array $violations = [])
    {
        $this->passed     = $passed;
        $this->violations = $violations;
    }

    public static function pass(): self
    {
        return new self(true, []);
    }

    public static function fail(array $violations): self
    {
        return new self(false, $violations);
    }

    public function passed(): bool
    {
        return $this->passed;
    }

    public function failed(): bool
    {
        return !$this->passed;
    }

    public function getViolations(): array
    {
        return $this->violations;
    }
}
