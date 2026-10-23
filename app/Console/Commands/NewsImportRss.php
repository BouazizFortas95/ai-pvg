<?php

namespace App\Console\Commands;

use App\Jobs\ImportRssSourceJob;
use App\Models\NewsSource;
use Illuminate\Console\Command;

class NewsImportRss extends Command
{
    protected $signature = 'news:import-rss {--source= : Specific source ID}';

    protected $description = 'Dispatch RSS import jobs for active sources';

    public function handle(): int
    {
        $query = NewsSource::query()->where('is_active', true)->whereNotNull('rss_url')->where('rss_url', '!=', '');
        if ($this->option('source')) {
            $query->where('id', $this->option('source'));
        }

        $sources = $query->get();
        $dispatched = 0;

        foreach ($sources as $source) {
            ImportRssSourceJob::dispatch($source->getKey());
            $dispatched++;
            $this->line('Dispatched import for source: '.$source->name.' (ID '.$source->getKey().')');
        }

        $this->info('Dispatched '.$dispatched.' import job(s).');

        return self::SUCCESS;
    }
}
