<?php

namespace App\Jobs;

use App\Enums\NewsStatus;
use App\Models\NewsSource;
use App\Models\RssImportLog;
use App\Services\Automation\Triage\TriageGate;
use App\Services\RssImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportRssSourceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public array $backoff = [30, 120];

    public function __construct(
        public int $newsSourceId
    ) {}

    public function uniqueId(): string
    {
        return 'rss-import-source-'.$this->newsSourceId;
    }

    public function uniqueFor(): int
    {
        return 60 * 30; // 30 minutes
    }

    public function handle(): void
    {
        $source = NewsSource::find($this->newsSourceId);
        if (! $source) {
            return;
        }
        if (! $source->is_active) {
            return;
        }
        if (empty($source->rss_url)) {
            return;
        }

        $lock = Cache::lock('rss-import-'.$this->newsSourceId, 600);
        if (! $lock->get()) {
            $this->release(60);

            return;
        }

        $log = RssImportLog::create([
            'news_source_id' => $source->getKey(),
            'status' => 'running',
            'total_items' => 0,
            'imported_items' => 0,
            'duplicate_items' => 0,
            'skipped_items' => 0,
            'failed_items' => 0,
            'error_message' => null,
            'started_at' => now(),
            'completed_at' => null,
        ]);

        $importedItems = 0;
        $service = app(RssImportService::class);
        $gate = app(TriageGate::class);

        try {
            // Queued ingestion is the autonomous path: imported items are
            // ready for G-02 rather than stranded as drafts. Direct service
            // callers still default to draft unless they opt in explicitly.
            $result = $service->import($source, [
                'status' => NewsStatus::Ready->value,
            ]);

            $log->update([
                'status' => empty($result->errors) ? 'completed' : 'completed',
                'total_items' => $result->totalItems,
                'imported_items' => $result->importedItems,
                'duplicate_items' => $result->duplicateItems,
                'skipped_items' => $result->skippedItems,
                'failed_items' => $result->failedItems,
                'error_message' => ! empty($result->errors) ? implode('; ', $result->errors) : null,
                'completed_at' => now(),
            ]);

            $source->update([
                'last_fetched_at' => now(),
            ]);

            $importedItems = $result->importedItems;
        } catch (\Exception $e) {
            $log->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);
        } finally {
            $lock->release();
        }

        if ($importedItems > 0
            && (bool) config('automation.triage.after_import', true)
            && $gate->enabled()) {
            $delay = max(0, (int) config('automation.triage.import_dispatch_delay_seconds', 30));

            try {
                RunTriageJob::dispatch()->delay(now()->addSeconds($delay));
            } catch (Throwable $exception) {
                // The articles were imported successfully; a queue outage must
                // not turn that into a failed import and duplicate retry.
                Log::warning('RSS import completed but automatic triage could not be queued.', [
                    'news_source_id' => $source->getKey(),
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
