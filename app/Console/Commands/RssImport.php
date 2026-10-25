<?php

namespace App\Console\Commands;

use App\Jobs\ImportRssSourceJob;
use App\Models\NewsSource;
use App\Models\RssImportLog;
use Illuminate\Console\Command;

class RssImport extends Command
{
    protected $signature = 'rss:import
        {--source= : Import one active source by ID}
        {--limit= : Optional total cap on newly imported articles}
        {--per-feed= : Optional per-source cap on newly imported articles}';

    protected $description = 'Fetch all available articles from every active RSS source immediately, with dynamic category discovery';

    public function handle(): int
    {
        $limit = $this->positiveIntegerOption('limit');
        $perFeed = $this->positiveIntegerOption('per-feed');

        if ($limit === false || $perFeed === false) {
            return self::FAILURE;
        }

        $query = NewsSource::query()
            ->where('is_active', true)
            ->whereNotNull('rss_url')
            ->where('rss_url', '!=', '');

        if ($this->option('source') !== null) {
            $sourceId = $this->option('source');

            if (! is_numeric($sourceId)) {
                $this->error('--source must be a numeric active source ID.');

                return self::FAILURE;
            }

            $query->whereKey((int) $sourceId);
        }

        $sources = $query->orderBy('id')->get();

        if ($sources->isEmpty()) {
            $this->warn('No active RSS sources matched. Nothing to import.');

            return self::SUCCESS;
        }

        $this->info('RSS import: all active sources, no category filter');
        $this->line('Sources: '.$sources->count().'; total cap: '.($limit ?? 'none').'; per-source cap: '.($perFeed ?? 'none'));

        $remaining = $limit;
        $totalImported = 0;
        $processed = 0;
        $failures = [];

        foreach ($sources as $source) {
            if ($remaining !== null && $remaining === 0) {
                break;
            }

            $batchLimit = match (true) {
                $remaining !== null && $perFeed !== null => min($remaining, $perFeed),
                $remaining !== null => $remaining,
                $perFeed !== null => $perFeed,
                default => null,
            };

            if ($batchLimit === 0) {
                break;
            }

            ImportRssSourceJob::dispatchSync((int) $source->getKey(), $batchLimit);
            $processed++;

            $log = RssImportLog::query()
                ->where('news_source_id', $source->getKey())
                ->latest('id')
                ->first();

            if ($log === null) {
                $failures[] = $source->name.': no import log was written.';
                $this->error($source->name.' failed: no import log was written.');

                continue;
            }

            $imported = (int) $log->imported_items;
            $totalImported += $imported;

            if ($remaining !== null) {
                $remaining = max(0, $remaining - $imported);
            }

            $this->line(sprintf(
                '%s: fetched=%d imported=%d duplicates=%d skipped=%d failed=%d categories=dynamic',
                $source->name,
                (int) $log->total_items,
                $imported,
                (int) $log->duplicate_items,
                (int) $log->skipped_items,
                (int) $log->failed_items,
            ));

            if ($log->status === 'failed') {
                $failures[] = $source->name.': '.($log->error_message ?? 'import failed.');
            }
        }

        $this->info("Imported {$totalImported} article(s) from {$processed} source(s).");

        if ($failures !== []) {
            $this->warn('Feed issues: '.implode(' | ', $failures));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return int|null|false Null when unlimited, false on invalid input.
     */
    private function positiveIntegerOption(string $name): int|null|false
    {
        $value = $this->option($name);

        if ($value === null) {
            return null;
        }

        if (! is_numeric($value) || (int) $value < 1) {
            $this->error("--{$name} must be at least 1 when provided.");

            return false;
        }

        return (int) $value;
    }
}
