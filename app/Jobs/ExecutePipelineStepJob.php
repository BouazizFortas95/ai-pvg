<?php

namespace App\Jobs;

use App\Models\PipelineRunStage;
use App\Services\Pipeline\PipelineStepService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class ExecutePipelineStepJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 600;

    /**
     * Indicate if the job should fail on timeout.
     */
    public bool $failOnTimeout = true;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    public function __construct(
        public int $stageId,
        public int $stepNumber,
        public array $options = [],
        public ?int $userId = null,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->stageId;
    }

    public function handle(PipelineStepService $service): void
    {
        $stage = PipelineRunStage::query()->with('run')->find($this->stageId);

        if (! $stage || $stage->isCancelled() || $stage->isCompleted()) {
            return;
        }

        // Transition from queued to running with real timestamps
        $stage->update([
            'status' => 'running',
            'started_at' => now(),
            'heartbeat_at' => now(),
        ]);

        Cache::put('pvg:queue_worker_heartbeat', now()->timestamp, 120);

        try {
            $stage->touchHeartbeat();

            $service->executeStep(
                stepNumber: $this->stepNumber,
                run: $stage->run,
                options: $this->options,
                userId: $this->userId,
                stage: $stage,
            );

            $stage->touchHeartbeat();
        } catch (Throwable $e) {
            $this->handleFailure($stage, $e);
        }
    }

    public function failed(?Throwable $e): void
    {
        $stage = PipelineRunStage::query()->with('run')->find($this->stageId);
        if ($stage && ! $stage->isCompleted()) {
            $this->handleFailure($stage, $e ?? new RuntimeException("Job timed out or worker crashed during Step {$this->stepNumber}."));
        }
    }

    private function handleFailure(PipelineRunStage $stage, Throwable $e): void
    {
        $fixHint = $this->determineFixHint($e);
        $stage->run?->markStepFailed(
            stepNumber: $this->stepNumber,
            errorMessage: $e->getMessage(),
            fixHint: $fixHint,
            log: "Exception in Step {$this->stepNumber}:\n".$e->getMessage()."\n".$e->getTraceAsString()
        );
    }

    private function determineFixHint(Throwable $e): string
    {
        $msg = strtolower($e->getMessage());

        if (str_contains($msg, 'ffmpeg') || str_contains($msg, 'ffprobe')) {
            return 'Ensure FFmpeg and FFprobe are installed and available in PATH (or configured in .env).';
        }

        if (str_contains($msg, 'node') || str_contains($msg, 'npm') || str_contains($msg, 'hyperframes')) {
            return 'Ensure Node 20+ and HyperFrames CLI are installed in hyperframes/ directory (run npm install).';
        }

        if (str_contains($msg, 'chrome')) {
            return 'Set REMOTION_CHROME_EXECUTABLE in .env pointing to Google Chrome or Chromium executable.';
        }

        if (str_contains($msg, 'script') || str_contains($msg, 'not found')) {
            return 'Run Step 3 first to generate and approve a video script.';
        }

        if (str_contains($msg, 'flag') || str_contains($msg, 'disabled')) {
            return 'Check "Auto-enable local pipeline flags" in Step 1 or pass --force on CLI.';
        }

        return 'Check the terminal output log below for complete stack trace and details.';
    }
}

