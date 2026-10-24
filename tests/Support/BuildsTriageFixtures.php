<?php

namespace Tests\Support;

use App\Enums\NewsStatus;
use App\Enums\StoryClusterStatus;
use App\Models\News;
use App\Models\NewsScore;
use App\Models\NewsSource;
use App\Models\StoryCluster;
use Illuminate\Support\Str;

trait BuildsTriageFixtures
{
    protected function makeNewsWithSource(string $title, ?NewsSource $source = null, array $attributes = []): News
    {
        $source ??= NewsSource::factory()->create();

        return News::factory()->create([
            'title' => $title,
            'slug' => Str::slug($title).'-'.$source->getKey().'-'.Str::random(4),
            'summary' => $title.' summary.',
            'content' => $title.' body text for deterministic matching.',
            'news_source_id' => $source->getKey(),
            'source_name' => $source->name,
            'status' => NewsStatus::Draft->value,
            'published_at' => now(),
            ...$attributes,
        ]);
    }

    /**
     * @param  array<int, News>  $news
     * @param  array<string, mixed>  $overrides
     */
    protected function makeStoryCluster(array $news, array $overrides = []): StoryCluster
    {
        $ids = array_map(fn (News $article): int => (int) $article->getKey(), $news);
        sort($ids);

        $claims = array_map(fn (News $article): array => [
            'claim' => (string) ($article->summary ?? $article->title),
            'source_news_ids' => [(int) $article->getKey()],
            'sources' => [[
                'news_id' => (int) $article->getKey(),
                'title' => (string) $article->title,
                'source_name' => $article->source_name,
                'source_url' => $article->source_url,
            ]],
            'confidence' => 'high',
        ], $news);

        $factSheet = $overrides['fact_sheet'] ?? [
            'version' => 'g02-fact-sheet-v1',
            'claims' => $claims,
            'entities' => [],
            'timeline' => array_map(fn (News $article): array => [
                'published_at' => $article->published_at?->toIso8601String(),
                'source_news_ids' => [(int) $article->getKey()],
            ], $news),
            'open_questions' => [],
        ];

        $cluster = StoryCluster::create([
            'fingerprint' => hash('sha256', implode('-', $ids)),
            'canonical_title' => (string) $news[0]->title,
            'canonical_summary' => (string) $news[0]->summary,
            'fact_sheet' => $factSheet,
            'status' => StoryClusterStatus::Open,
            'confidence' => 90,
            'window_start' => $news[0]->published_at,
            'window_end' => $news[0]->published_at,
            'source_count' => count(array_unique(array_map(
                fn (News $article): string => (string) $article->news_source_id,
                $news,
            ))),
            ...$overrides,
        ]);

        foreach ($news as $index => $article) {
            $cluster->news()->attach($article->getKey(), [
                'similarity' => $index === 0 ? 1.0 : 0.9,
                'is_primary' => $index === 0,
            ]);
        }

        return $cluster->fresh(['news.newsScore']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeNewsScore(News $news, float $composite, array $overrides = []): NewsScore
    {
        return NewsScore::create([
            'news_id' => $news->getKey(),
            'velocity_score' => $composite,
            'keyword_heat_score' => $composite,
            'novelty_score' => $composite,
            'source_diversity_score' => $composite,
            'recency_score' => $composite,
            'composite_score' => $composite,
            'band' => $composite >= 85 ? 'auto_greenlight' : ($composite >= 60 ? 'review' : 'below_threshold'),
            'breakdown' => ['version' => 'test', 'composite_score' => $composite],
            'scored_at' => now(),
            ...$overrides,
        ]);
    }
}
