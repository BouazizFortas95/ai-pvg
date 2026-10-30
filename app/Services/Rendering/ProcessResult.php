<?php

namespace App\Services\Rendering;

final class ProcessResult
{
    public function __construct(
        public readonly bool $successful,
        public readonly int $exitCode,
        public readonly string $output,
        public readonly string $errorOutput,
    ) {}
}
