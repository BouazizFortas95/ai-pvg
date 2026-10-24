<?php

namespace App\Services\AI\ScriptPipeline;

use App\Models\News;
use App\Models\StoryCluster;
use App\Services\Automation\Clustering\StoryClusteringService;
use Illuminate\Support\Str;

class ScriptSourceFactory
{
    public function __construct(private readonly StoryClusteringService $clustering) {}

    public function fromModel(StoryCluster|News $subject, ?int $targetDuration = null, ?string $language = null): ScriptSource
    {
        $target = max(10, $targetDuration ?? (int) config('automation.script_agents.target_duration', 45));
        $language = trim($language ?? (string) config('automation.script_agents.language', 'en'));

        if ($language === '') {
            $language = 'en';
        }

        return $subject instanceof StoryCluster
            ? $this->fromCluster($subject, $target, $language)
            : $this->fromNews($subject, $target, $language);
    }

    private function fromCluster(StoryCluster $cluster, int $target, string $language): ScriptSource
    {
        $cluster->loadMissing(['news.source']);

        $news = $this->normalizeNews($cluster->news->all());

        if ($news === []) {
            throw new \RuntimeException('Story cluster has no member news items.');
        }

        $validIds = array_map(fn (array $item): int => (int) $item['id'], $news);
        $factSheet = is_array($cluster->fact_sheet) ? $cluster->fact_sheet : [];
        $factSheet = $this->clustering->sanitizeFactSheet($factSheet, $validIds);
        $claims = $this->claimsFromFactSheet($factSheet, $news, $validIds);

        return new ScriptSource(
            windowKey: 'cluster-'.$cluster->getKey().':g03-v1',
            sourceKey: 'cluster-'.$cluster->getKey(),
            title: (string) $cluster->canonical_title,
            summary: $cluster->canonical_summary,
            news: $news,
            claims: $claims,
            factSheet: $factSheet,
            language: $language,
            targetDuration: $target,
        );
    }

    private function fromNews(News $news, int $target, string $language): ScriptSource
    {
        $news->loadMissing('source');

        $item = $this->normalizeNews([$news])[0];
        $claimText = trim((string) ($news->summary ?? ''));

        if ($claimText === '') {
            $claimText = Str::limit(strip_tags((string) ($news->content ?? $news->title)), 300, '');
        }

        $claims = [[
            'claim' => $claimText !== '' ? $claimText : (string) $news->title,
            'source_news_ids' => [(int) $news->getKey()],
        ]];

        $factSheet = [
            'version' => 'g03-single-news-v1',
            'canonical_title' => (string) $news->title,
            'claims' => $claims,
            'bullets' => [[
                'text' => $claims[0]['claim'],
                'source_news_ids' => $claims[0]['source_news_ids'],
            ]],
            'entities' => [],
            'timeline' => [],
            'open_questions' => [],
        ];

        return new ScriptSource(
            windowKey: 'news-'.$news->getKey().':g03-v1',
            sourceKey: 'news-'.$news->getKey(),
            title: (string) $news->title,
            summary: $news->summary,
            news: [$item],
            claims: $claims,
            factSheet: $factSheet,
            language: $language,
            targetDuration: $target,
        );
    }

    /**
     * @param  array<int, News>  $items
     * @return list<array{id: int, title: string, summary: ?string, content: ?string, source_name: ?string, source_url: ?string}>
     */
    private function normalizeNews(array $items): array
    {
        $normalized = [];

        foreach ($items as $article) {
            $normalized[] = [
                'id' => (int) $article->getKey(),
                'title' => (string) $article->title,
                'summary' => $article->summary,
                'content' => $article->content !== null ? (string) $article->content : null,
                'source_name' => $article->source?->name ?? $article->source_name,
                'source_url' => $article->source_url,
            ];
        }

        usort($normalized, fn (array $left, array $right): int => $left['id'] <=> $right['id']);

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $factSheet
     * @param  list<array{id: int, title: string, summary: ?string, content: ?string, source_name: ?string, source_url: ?string}>  $news
     * @param  list<int>  $validIds
     * @return list<array{claim: string, source_news_ids: list<int>}>
     */
    private function claimsFromFactSheet(array $factSheet, array $news, array $validIds): array
    {
        $claims = [];

        foreach ((array) ($factSheet['claims'] ?? []) as $claim) {
            if (! is_array($claim) || ! isset($claim['claim']) || ! is_string($claim['claim'])) {
                continue;
            }

            $ids = array_values(array_unique(array_intersect(
                array_map('intval', (array) ($claim['source_news_ids'] ?? [])),
                $validIds,
            )));

            if ($ids === [] || trim($claim['claim']) === '') {
                continue;
            }

            $claims[] = [
                'claim' => trim($claim['claim']),
                'source_news_ids' => $ids,
            ];
        }

        if ($claims !== []) {
            return $claims;
        }

        foreach ($news as $item) {
            $text = trim((string) ($item['summary'] ?? ''));

            if ($text === '') {
                $text = Str::limit(strip_tags((string) ($item['content'] ?? $item['title'])), 300, '');
            }

            $claims[] = [
                'claim' => $text !== '' ? $text : (string) $item['title'],
                'source_news_ids' => [(int) $item['id']],
            ];
        }

        return $claims;
    }
}
