<?php

namespace App\Console\Commands;

use App\Services\Pipeline\PipelineStepService;
use Illuminate\Console\Command;
use Throwable;

class PipelineStepCommand extends Command
{
    protected $signature = 'ai-pvg:pipeline-step
        {step : Step number from 1 to 6}
        {--run-id= : Existing pipeline run ID}
        {--queue : Dispatch step as asynchronous queued job}
        {--options= : JSON encoded string of step options}';

    protected $description = 'Execute or queue a pipeline wizard step via the shared PipelineStepService (CLI parity)';

    public function handle(PipelineStepService $service): int
    {
        $step = (int) $this->argument('step');
        if ($step < 1 || $step > 6) {
            $this->error("Invalid step number {$step}. Must be between 1 and 6.");

            return self::FAILURE;
        }

        $optionsJson = (string) $this->option('options');
        $options = [];
        if ($optionsJson !== '') {
            $decoded = json_decode($optionsJson, true);
            if (is_array($decoded)) {
                $options = $decoded;
            }
        }

        $runId = $this->option('run-id');
        $run = $service->findOrCreateRun($options, null, $runId);

        $this->line('');
        $this->line("<fg=cyan;options=bold>AI-PVG Pipeline Step Runner — Step {$step} ({$service::STEP_NAMES[$step]})</fg=cyan;options=bold>");
        $this->line("Run ID: <fg=yellow>{$run->run_id}</fg=yellow>");
        $this->line(str_repeat('─', 50));

        if ($this->option('queue')) {
            $stage = $service->dispatchStep($step, $options, $run);
            $this->info("Step {$step} dispatched as queued job (Stage ID: {$stage->id}, Status: {$stage->status}).");

            return self::SUCCESS;
        }

        try {
            $result = $service->executeStep($step, $run, $options);
            $this->line($result['log']);
            $this->info("Step {$step} completed successfully.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error("Step {$step} failed: ".$e->getMessage());

            return self::FAILURE;
        }
    }
}
