<?php

namespace App\Jobs;

use App\Services\Automation\Triage\TriageGate;
use App\Services\Automation\Triage\TriageRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Gated post-import handshake: score fresh ready articles, cluster recent
 * coverage, and let auto-greenlight prepare (or plan) the downstream script.
 * The job is idempotent and performs no network calls.
 */
class RunTriageJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [30, 120, 300];

    public function uniqueId(): string
    {
        return 'ai-pvg-triage-after-import';
    }

    public function uniqueFor(): int
    {
        return 600;
    }

    public function handle(TriageGate $gate, TriageRunner $runner): void
    {
        if (! $gate->enabled()) {
            Log::info('Automatic triage skipped because the triage gate is disabled.');

            return;
        }

        $report = $runner->run(true, true);

        Log::info('Automatic triage completed.', [
            'scored' => $report->scoredCount(),
            'clusters' => $report->clusterCount(),
            'greenlights' => $report->greenlightCount(),
            'cluster_diagnostics' => $report->clusterDiagnostics,
        ]);
    }
}
