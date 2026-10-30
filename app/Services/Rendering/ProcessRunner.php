<?php

namespace App\Services\Rendering;

use Symfony\Component\Process\Process;

/**
 * Thin, mockable wrapper around Symfony Process. Commands are always passed
 * as argument arrays so no shell interpolation can occur.
 */
class ProcessRunner
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     */
    public function run(array $command, ?string $cwd = null, array $env = [], ?int $timeout = null): ProcessResult
    {
        $process = new Process($command, $cwd, $env !== [] ? $env : null, null, $timeout ?? 600);
        $process->setIdleTimeout(null);
        $process->run();

        return new ProcessResult(
            successful: $process->isSuccessful(),
            exitCode: (int) ($process->getExitCode() ?? 1),
            output: $process->getOutput(),
            errorOutput: $process->getErrorOutput(),
        );
    }
}
