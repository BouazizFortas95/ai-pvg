<?php

namespace App\Services\Automation\Clustering;

use App\Enums\NewsStatus;
use App\Enums\StoryClusterStatus;
use App\Models\News;
use App\Models\StoryCluster;
use App\Services\Automation\Triage\TriageGate;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class StoryClusteringService
{
    public function __construct(
        private readonly TriageGate $gate,
        private readonly TextSimilarityService $similarity,
    ) {}

    /**
     * Build deterministic clusters from recent articles. Only clusters with
     * three to five distinct sources are persisted.
     *
     * @return list<StoryCluster>
     */
    public function cluster(?DateTimeInterface $asOf = null, ?int $limit = null): array
    {
        $this->gate->assertEnabled();

        $asOf = $asOf ? CarbonImmutable::instance($asOf) : CarbonImmutable::now();
        $windowHours = max(1, (int) config('automation.story_clustering.window_hours', 48));
        $windowStart = $asOf->subHours($windowHours);
        $limit = max(3, $limit ?? (int) config('automation.story_clustering.candidate_limit', 200));
        $threshold = (float) config(
            'automation.story_clustering.similarity_threshold',
            config('automation.news_scoring.cluster_similarity_threshold', 0.82),
        );

        $news = News::query()
            ->with(['source', 'newsCategory'])
            ->whereNotNull('published_at')
            ->whereBetween('published_at', [$windowStart, $asOf])
            ->where('status', '!=', NewsStatus::Rejected->value)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        if ($news->count() < 3) {
            return [];
        }

        $newsById = $news->keyBy(fn (News $article): int => (int) $article->getKey());
        $documents = [];

        foreach ($news as $article) {
            $documents[(int) $article->getKey()] = $this->similarity->documentTokens(
                (string) $article->title,
                $article->summary,
                $article->content,
            );
        }

        $vectors = $this->similarity->tfidfVectors($documents);
        $cache = [];
        $pairSimilarity = function (int $left, int $right) use (&$cache, $vectors): float {
            $key = min($left, $right).':'.max($left, $right);

            return $cache[$key] ??= $this->similarity->cosine($vectors[$left] ?? [], $vectors[$right] ?? []);
        };

        $components = $this->connectedComponents(array_keys($documents), $pairSimilarity, $threshold);
        $minSources = min(5, max(3, (int) config('automation.story_clustering.min_sources', 3)));
        $maxSources = min(5, max($minSources, (int) config('automation.story_clustering.max_sources', 5)));
        $clusters = [];

        foreach ($components as $component) {
            foreach ($this->partition($component, $minSources, $maxSources) as $chunk) {
                $members = collect($chunk)
                    ->map(fn (int $id): ?News => $newsById->get($id))
                    ->filter()
                    ->values();

                if ($members->count() < 3 || $members->count() > 5) {
                    continue;
                }

                $sourceCount = $members
                    ->map(fn (News $article): string => $this->sourceKey($article))
                    ->unique()
                    ->count();

                if ($sourceCount < $minSources) {
                    continue;
                }

                $primary = $members->first();
                $ids = $members->map(fn (News $article): int => (int) $article->getKey())->all();
                sort($ids);
                $fingerprint = hash('sha256', implode('-', $ids));

                $canonicalSummary = trim((string) ($primary->summary ?? ''));

                if ($canonicalSummary === '') {
                    $canonicalSummary = Str::limit(strip_tags((string) $primary->content), 500, '');
                }

                $windowStartAt = $members
                    ->map(fn (News $article) => $article->published_at ?? $article->created_at)
                    ->filter()
                    ->min();
                $windowEndAt = $members
                    ->map(fn (News $article) => $article->published_at ?? $article->created_at)
                    ->filter()
                    ->max();

                $cluster = StoryCluster::query()->firstOrNew(['fingerprint' => $fingerprint]);
                $isNew = ! $cluster->exists;

                $cluster->fill([
                    'canonical_title' => (string) $primary->title,
                    'canonical_summary' => $canonicalSummary !== '' ? $canonicalSummary : null,
                    'fact_sheet' => $this->buildFactSheet($members, $primary),
                    'confidence' => $this->confidence($ids, $pairSimilarity),
                    'window_start' => $windowStartAt,
                    'window_end' => $windowEndAt,
                    'source_count' => $sourceCount,
                ]);

                if ($isNew) {
                    $cluster->status = StoryClusterStatus::Open;
                }

                $cluster->save();

                $pivot = [];
                $primaryId = (int) $primary->getKey();

                foreach ($members as $member) {
                    $memberId = (int) $member->getKey();
                    $pivot[$memberId] = [
                        'similarity' => $memberId === $primaryId
                            ? 1.0
                            : round($pairSimilarity($primaryId, $memberId), 4),
                        'is_primary' => $memberId === $primaryId,
                    ];
                }

                $cluster->news()->sync($pivot);
                $clusters[] = $cluster->fresh(['news.newsScore']);
            }
        }

        return $clusters;
    }

    /**
     * Strip any claim or bullet that cannot be tied to at least one valid
     * member news_id. Invalid ids inside an otherwise grounded item are
     * removed as well.
     *
     * @param  array<string, mixed>  $factSheet
     * @param  array<int, int|string>  $validNewsIds
     * @return array<string, mixed>
     */
    public function sanitizeFactSheet(array $factSheet, array $validNewsIds): array
    {
        $valid = array_values(array_unique(array_map('intval', $validNewsIds)));

        foreach (['claims', 'entities', 'timeline', 'open_questions', 'bullets'] as $section) {
            if (! isset($factSheet[$section]) || ! is_array($factSheet[$section])) {
                continue;
            }

            $clean = [];

            foreach ($factSheet[$section] as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $sourceIds = $item['source_news_ids'] ?? null;

                if (! is_array($sourceIds)) {
                    continue;
                }

                $sourceIds = array_values(array_unique(array_intersect(
                    array_map('intval', $sourceIds),
                    $valid,
                )));

                if ($sourceIds === []) {
                    continue;
                }

                $item['source_news_ids'] = $sourceIds;

                if (isset($item['sources']) && is_array($item['sources'])) {
                    $sources = [];

                    foreach ($item['sources'] as $source) {
                        if (! is_array($source) || ! isset($source['news_id'])) {
                            continue;
                        }

                        if (in_array((int) $source['news_id'], $sourceIds, true)) {
                            $sources[] = $source;
                        }
                    }

                    $item['sources'] = $sources;
                }

                $clean[] = $item;
            }

            $factSheet[$section] = $clean;
        }

        return $factSheet;
    }

    /**
     * @param  Collection<int, News>  $members
     * @return array<string, mixed>
     */
    private function buildFactSheet(Collection $members, News $primary): array
    {
        $validIds = $members->map(fn (News $article): int => (int) $article->getKey())->all();
        $claims = [];
        $timeline = [];
        $entities = [];

        foreach ($members as $member) {
            $memberId = (int) $member->getKey();
            $claim = $this->claimText($member);

            if ($claim !== '') {
                $claims[] = [
                    'claim' => $claim,
                    'source_news_ids' => [$memberId],
                    'sources' => [[
                        'news_id' => $memberId,
                        'title' => (string) $member->title,
                        'source_name' => $member->source?->name ?? $member->source_name,
                        'source_url' => $member->source_url,
                    ]],
                    'confidence' => 'high',
                ];
            }

            $publishedAt = $member->published_at ?? $member->created_at;

            if ($publishedAt !== null) {
                $timeline[] = [
                    'published_at' => $publishedAt->toIso8601String(),
                    'source_news_ids' => [$memberId],
                ];
            }

            $entityName = $member->newsCategory?->name ?? $member->category;

            if (is_string($entityName) && trim($entityName) !== '') {
                $entityName = trim($entityName);

                if (! isset($entities[$entityName])) {
                    $entities[$entityName] = [
                        'name' => $entityName,
                        'source_news_ids' => [],
                    ];
                }

                $entities[$entityName]['source_news_ids'][] = $memberId;
            }
        }

        $factSheet = [
            'version' => 'g02-fact-sheet-v1',
            'canonical_title' => (string) $primary->title,
            'claims' => $claims,
            'bullets' => array_map(fn (array $claim): array => [
                'text' => $claim['claim'],
                'source_news_ids' => $claim['source_news_ids'],
            ], $claims),
            'entities' => array_values(array_map(function (array $entity): array {
                $entity['source_news_ids'] = array_values(array_unique($entity['source_news_ids']));

                return $entity;
            }, $entities)),
            'timeline' => $timeline,
            'open_questions' => [],
        ];

        return $this->sanitizeFactSheet($factSheet, $validIds);
    }

    /**
     * @param  list<int>  $ids
     * @param  callable(int, int): float  $pairSimilarity
     * @return list<list<int>>
     */
    private function connectedComponents(array $ids, callable $pairSimilarity, float $threshold): array
    {
        $visited = [];
        $components = [];

        foreach ($ids as $id) {
            if (isset($visited[$id])) {
                continue;
            }

            $visited[$id] = true;
            $stack = [$id];
            $component = [];

            while ($stack !== []) {
                $current = array_pop($stack);
                $component[] = $current;

                foreach ($ids as $other) {
                    if ($other === $current || isset($visited[$other])) {
                        continue;
                    }

                    if ($pairSimilarity($current, $other) >= $threshold) {
                        $visited[$other] = true;
                        $stack[] = $other;
                    }
                }
            }

            if (count($component) >= 3) {
                $components[] = $component;
            }
        }

        return $components;
    }

    /**
     * Split a component into balanced chunks of three to five members.
     *
     * @param  list<int>  $ids
     * @return list<list<int>>
     */
    private function partition(array $ids, int $min, int $max): array
    {
        $chunks = [];

        while (count($ids) > $max) {
            $chunks[] = array_splice($ids, 0, $max);
        }

        if ($ids !== []) {
            $chunks[] = $ids;
        }

        $last = count($chunks) - 1;

        while ($last > 0 && count($chunks[$last]) < $min && count($chunks[$last - 1]) > $min) {
            $moved = array_pop($chunks[$last - 1]);
            array_unshift($chunks[$last], $moved);
        }

        return array_values(array_filter(
            $chunks,
            fn (array $chunk): bool => count($chunk) >= $min && count($chunk) <= $max,
        ));
    }

    /**
     * @param  list<int>  $ids
     * @param  callable(int, int): float  $pairSimilarity
     */
    private function confidence(array $ids, callable $pairSimilarity): float
    {
        $pairs = 0;
        $total = 0.0;

        for ($i = 0; $i < count($ids); $i++) {
            for ($j = $i + 1; $j < count($ids); $j++) {
                $total += $pairSimilarity($ids[$i], $ids[$j]);
                $pairs++;
            }
        }

        return $pairs === 0 ? 0.0 : round(($total / $pairs) * 100.0, 2);
    }

    private function claimText(News $news): string
    {
        $text = trim((string) ($news->summary ?? ''));

        if ($text === '') {
            $text = trim(strip_tags((string) $news->content));
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if ($text === '') {
            return '';
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $text) ?: [];
        $claim = trim((string) ($sentences[0] ?? $text));

        return Str::limit($claim !== '' ? $claim : $text, 280, '…');
    }

    private function sourceKey(News $news): string
    {
        if ($news->news_source_id !== null) {
            return 'source:'.$news->news_source_id;
        }

        $sourceName = mb_strtolower(trim((string) $news->source_name));

        if ($sourceName !== '') {
            return 'name:'.$sourceName;
        }

        $host = parse_url((string) $news->source_url, PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            return 'host:'.mb_strtolower($host);
        }

        return 'news:'.$news->getKey();
    }
}
