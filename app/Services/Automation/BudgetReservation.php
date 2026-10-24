<?php

namespace App\Services\Automation;

final readonly class BudgetReservation
{
    public function __construct(
        public int $periodId,
        public int $amountMicros,
        public ?int $runId = null,
        public ?string $provider = null,
    ) {}
}
