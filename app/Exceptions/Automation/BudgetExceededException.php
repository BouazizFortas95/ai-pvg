<?php

namespace App\Exceptions\Automation;

use RuntimeException;

class BudgetExceededException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $tripsBreaker = false)
    {
        parent::__construct($message);
    }

    public static function perCall(int $requested, int $limit): self
    {
        return new self("Per-call budget exceeded: requested {$requested} micros, limit is {$limit} micros.");
    }

    public static function monthly(int $requested, int $limit): self
    {
        return new self(
            "Monthly automation budget exceeded: requested {$requested} micros, limit is {$limit} micros.",
            tripsBreaker: true,
        );
    }

    public static function perRun(int $requested, int $limit): self
    {
        return new self("Per-run budget exceeded: requested {$requested} micros, limit is {$limit} micros.");
    }

    public static function breakerOpen(): self
    {
        return new self('Automation budget circuit breaker is open.');
    }
}
