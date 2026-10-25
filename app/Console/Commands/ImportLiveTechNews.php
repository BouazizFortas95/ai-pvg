<?php

namespace App\Console\Commands;

use App\Enums\NewsStatus;
use App\Models\News;
use App\Models\NewsCategory;
use App\Models\NewsSource;
use App\Services\RssImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

class ImportLiveTechNews extends Command
{
    /**
     * Verified public technology feeds. Sources are stored inactive so the
     * scheduled news:import-rss command never adds articles in the
     * background; only this explicit command imports them.
     *
     * @var array<string, array{name: string, slug: string, website_url: string, rss_url: string, description: string}>
     */
    private const FEEDS = [
        'techcrunch' => [
            'name' => 'TechCrunch',
            'slug' => 'techcrunch',
            'website_url' => 'https://techcrunch.com/',
            'rss_url' => 'https://techcrunch.com/feed/',
            'description' => 'Technology startup and industry news.',
        ],
        'verge' => [
            'name' => 'The Verge',
            'slug' => 'the-verge',
            'website_url' => 'https://www.theverge.com/',
            'rss_url' => 'https://www.theverge.com/rss/index.xml',
            'description' => 'Technology, science, and culture news.',
        ],
        'arstechnica' => [
            'name' => 'Ars Technica',
            'slug' => 'ars-technica',
            'website_url' => 'https://arstechnica.com/',
            'rss_url' => 'https://feeds.arstechnica.com/arstechnica/index',
            'description' => 'Technology news and analysis.',
        ],
        'github' => [
            'name' => 'GitHub Blog',
            'slug' => 'github-blog',
            'website_url' => 'https://github.blog/',
            'rss_url' => 'https://github.blog/feed/',
            'description' => 'Engineering, open source, and developer news from GitHub.',
        ],
        'hackernews' => [
            'name' => 'Hacker News',
            'slug' => 'hacker-news',
            'website_url' => 'https://news.ycombinator.com/',
            'rss_url' => 'https://hnrss.org/frontpage',
            'description' => 'Front-page technology links from Hacker News.',
        ],
    ];

    protected $signature = 'news:import-live-tech
        {--limit=10 : Total ready articles to ensure across the selected live sources}
        {--per-feed=3 : Maximum new articles to import from each feed per run}
        {--feed=* : Feed keys to use (techcrunch, verge, arstechnica, github, hackernews)}';

    protected $description = 'Fetch verified public tech RSS feeds and populate the local news table (no AI, rendering, or publishing)';

    public function handle(RssImportService $importer): int
    {
        $limit = (int) $this->option('limit');
        $perFeed = (int) $this->option('per-feed');
        $selected = $this->selectedFeeds();

        if ($limit < 1 || $perFeed < 1) {
            $this->error('--limit and --per-feed must both be at least 1.');

            return self::FAILURE;
        }

        if ($selected === []) {
            return self::FAILURE;
        }

        $category = NewsCategory::query()->firstOrCreate(
            ['slug' => 'technology'],
            [
                'name' => 'Technology',
                'description' => 'Technology news and industry coverage.',
                'color' => '#3b82f6',
                'is_active' => true,
            ],
        );

        $sources = [];
        $sourceIds = [];

        foreach ($selected as $key => $feed) {
            $source = NewsSource::query()->firstOrCreate(
                ['slug' => $feed['slug']],
                [
                    'name' => $feed['name'],
                    'website_url' => $feed['website_url'],
                    'rss_url' => $feed['rss_url'],
                    'description' => $feed['description'],
                    'language' => 'en',
                    'is_active' => false,
                ],
            );

            if (blank($source->rss_url)) {
                $source->forceFill([
                    'rss_url' => $feed['rss_url'],
                    'is_active' => false,
                ])->save();
            }

            $sources[$key] = [$feed, $source];
            $sourceIds[] = (int) $source->getKey();
        }

        $existing = News::query()
            ->whereIn('news_source_id', $sourceIds)
            ->where('status', NewsStatus::Ready->value)
            ->count();

        $this->info('Live tech feed import');
        $this->line('Category: '.$category->name.' (ID '.$category->getKey().')');
        $this->line("Existing ready articles from selected sources: {$existing}");
        $this->line("Target: {$limit}; per-feed cap: {$perFeed}");

        $remaining = max(0, $limit - $existing);

        if ($remaining === 0) {
            $this->info('Target already met; no new articles imported.');

            return self::SUCCESS;
        }

        /** @var Collection<int, News> $inserted */
        $inserted = collect();
        $failures = [];

        foreach ($sources as $key => [$feed, $source]) {
            if ($remaining === 0) {
                break;
            }

            $batchLimit = min($remaining, $perFeed);
            $maxIdBefore = (int) (News::query()->max('id') ?? 0);

            try {
                $result = $importer->import($source, [
                    'limit' => $batchLimit,
                    'status' => NewsStatus::Ready->value,
                    'news_category_id' => (int) $category->getKey(),
                    'category' => 'technology',
                ]);
            } catch (Throwable $exception) {
                $failures[] = $feed['name'].': '.$exception->getMessage();
                $this->error($feed['name'].' failed: '.$exception->getMessage());

                continue;
            }

            $source->forceFill(['last_fetched_at' => now()])->save();

            $newRows = News::query()
                ->where('id', '>', $maxIdBefore)
                ->orderBy('id')
                ->get();

            $inserted = $inserted->merge($newRows);
            $remaining = max(0, $remaining - $newRows->count());

            $this->line(sprintf(
                '%s: fetched=%d imported=%d duplicates=%d skipped=%d failed=%d',
                $feed['name'],
                $result->totalItems,
                $result->importedItems,
                $result->duplicateItems,
                $result->skippedItems,
                $result->failedItems,
            ));

            if ($result->errors !== []) {
                $failures[] = $feed['name'].': '.implode('; ', $result->errors);
                $this->warn('  '.implode('; ', $result->errors));
            }

            if ($key !== array_key_last($sources) && $remaining > 0) {
                usleep(250_000); // Be polite to public feeds between requests.
            }
        }

        if ($inserted->isNotEmpty()) {
            $this->table(
                ['ID', 'Source', 'Published', 'Title'],
                $inserted->map(fn (News $news): array => [
                    $news->getKey(),
                    $news->source_name,
                    $news->published_at?->toDateString() ?? 'missing',
                    $news->title,
                ])->all(),
            );
        }

        $ready = News::query()
            ->whereIn('news_source_id', $sourceIds)
            ->where('status', NewsStatus::Ready->value)
            ->count();

        $this->info("Inserted this run: {$inserted->count()}. Ready from selected sources: {$ready}/{$limit}.");

        if ($failures !== []) {
            $this->warn('Feed issues: '.implode(' | ', $failures));
        }

        if ($ready < $limit) {
            $this->error('Target not reached. Re-run the command or review the feed issues above.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{name: string, slug: string, website_url: string, rss_url: string, description: string}>
     */
    private function selectedFeeds(): array
    {
        $requested = array_values(array_filter((array) $this->option('feed')));

        if ($requested === []) {
            return self::FEEDS;
        }

        $selected = [];

        foreach ($requested as $key) {
            $key = strtolower(trim((string) $key));

            if (! isset(self::FEEDS[$key])) {
                $this->error("Unknown feed key [{$key}]. Available: ".implode(', ', array_keys(self::FEEDS)));

                return [];
            }

            $selected[$key] = self::FEEDS[$key];
        }

        return $selected;
    }
}
