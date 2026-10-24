<?php

namespace App\Services\Automation\Scoring;

use App\Enums\NewsStatus;
use App\Enums\TriageBand;
use App\Models\News;
use App\Models\NewsScore;
use App\Services\Automation\Clustering\TextSimilarityService;
use App\Services\Automation\Triage\TriageGate;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class NewsScoringService
{
    public function __construct(
        private readonly TriageGate $gate,
        private readonly TextSimilarityService $similarity,
    ) {}

    /**
     * Score one article using only local data. Supplying the same $asOf and
     * database contents always produces the same components and breakdown.
     */
    public function score(News $news, ?DateTimeInterface $asOf = null): NewsScore
    {
        $this->gate->assertEnabled();

        $asOf = $asOf ? CarbonImmutable::instance($asOf) : CarbonImmutable::now();
        $windowHours = max(1, (int) config('automation.news_scoring.velocity_window_hours', 12));
        $windowStart = $asOf->subHours($windowHours);

        $news->loadMissing('source');

        $coverageNews = $this->coverageNews($news, $asOf, $windowStart);
        $coverage = collect([$news])->merge($coverageNews)->unique('id')->values();
        $sourceKeys = $coverage->map(fn (News $article): string => $this->sourceKey($article))->unique()->values();
        $sourceCount = max(1, $sourceKeys->count());
        $targetSources = max(1, (int) config('automation.news_scoring.velocity_target_sources', 5));

        $velocityScore = $this->round(min(100.0, ($sourceCount / $targetSources) * 100.0));
        $keywordHeat = $this->keywordHeat($news);
        $novelty = $this->novelty($news, $asOf);
        $sourceDiversity = $this->round(min(100.0, ($sourceCount / $targetSources) * 100.0));
        $recency = $this->recency($news, $asOf);

        $scores = [
            'velocity' => $velocityScore,
            'keyword_heat' => $keywordHeat['score'],
            'novelty' => $novelty['score'],
            'source_diversity' => $sourceDiversity,
            'recency' => $recency['score'],
        ];

        $weights = $this->weights();
        $weightTotal = array_sum($weights);

        $weighted = [];
        $composite = 0.0;

        foreach ($scores as $key => $value) {
            $weighted[$key] = $this->round(($value * $weights[$key]) / $weightTotal);
            $composite += $weighted[$key];
        }

        $composite = $this->round(min(100.0, max(0.0, $composite)));
        $thresholds = $this->thresholds();
        $band = TriageBand::fromScore(
            $composite,
            $thresholds['auto_greenlight_min'],
            $thresholds['review_band_min'],
        );

        $breakdown = [
            'version' => 'g02-news-score-v1',
            'as_of' => $asOf->toIso8601String(),
            'window' => [
                'hours' => $windowHours,
                'start' => $windowStart->toIso8601String(),
                'end' => $asOf->toIso8601String(),
            ],
            'thresholds' => $thresholds,
            'weights' => $weights,
            'component_scores' => $scores,
            'weighted_contributions' => $weighted,
            'components' => [
                'velocity' => [
                    'score' => $velocityScore,
                    'weight' => $weights['velocity'],
                    'weighted' => $weighted['velocity'],
                    'evidence' => [
                        'distinct_sources' => $sourceCount,
                        'target_sources' => $targetSources,
                        'coverage_news_ids' => $coverage->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
                        'source_keys' => $sourceKeys->all(),
                    ],
                ],
                'keyword_heat' => [
                    'score' => $keywordHeat['score'],
                    'weight' => $weights['keyword_heat'],
                    'weighted' => $weighted['keyword_heat'],
                    'evidence' => $keywordHeat['evidence'],
                ],
                'novelty' => [
                    'score' => $novelty['score'],
                    'weight' => $weights['novelty'],
                    'weighted' => $weighted['novelty'],
                    'evidence' => $novelty['evidence'],
                ],
                'source_diversity' => [
                    'score' => $sourceDiversity,
                    'weight' => $weights['source_diversity'],
                    'weighted' => $weighted['source_diversity'],
                    'evidence' => [
                        'distinct_sources' => $sourceCount,
                        'target_sources' => $targetSources,
                    ],
                ],
                'recency' => [
                    'score' => $recency['score'],
                    'weight' => $weights['recency'],
                    'weighted' => $weighted['recency'],
                    'evidence' => $recency['evidence'],
                ],
            ],
            'composite_score' => $composite,
            'band' => $band->value,
            'explanations' => [
                "Velocity: {$sourceCount} distinct source(s) in the last {$windowHours}h (target {$targetSources}).",
                'Keyword heat: matched '.($keywordHeat['evidence']['matched_keywords'] === []
                    ? 'no configured keywords'
                    : implode(', ', $keywordHeat['evidence']['matched_keywords'])).'.',
                'Novelty: '.($novelty['evidence']['nearest_news_id'] === null
                    ? 'no comparable recent headline'
                    : 'closest recent headline #'.$novelty['evidence']['nearest_news_id']
                        .' at '.$novelty['evidence']['max_similarity'].' similarity').'.',
                "Source diversity: {$sourceCount} distinct source(s).",
                'Recency: '.$recency['evidence']['age_hours'].'h old with a '
                    .$recency['evidence']['half_life_hours'].'h half-life.',
            ],
        ];

        $score = NewsScore::query()->updateOrCreate(
            ['news_id' => $news->getKey()],
            [
                'velocity_score' => $scores['velocity'],
                'keyword_heat_score' => $scores['keyword_heat'],
                'novelty_score' => $scores['novelty'],
                'source_diversity_score' => $scores['source_diversity'],
                'recency_score' => $scores['recency'],
                'composite_score' => $composite,
                'band' => $band,
                'breakdown' => $breakdown,
                'scored_at' => $asOf,
            ],
        );

        return $score->load('news');
    }

    /**
     * @param  iterable<News>  $news
     * @return array<int, NewsScore>
     */
    public function scoreMany(iterable $news, ?DateTimeInterface $asOf = null): array
    {
        $asOf = $asOf ? CarbonImmutable::instance($asOf) : CarbonImmutable::now();
        $scores = [];

        foreach ($news as $article) {
            $score = $this->score($article, $asOf);
            $scores[(int) $score->news_id] = $score;
        }

        return $scores;
    }

    /**
     * @return Collection<int, News>
     */
    private function coverageNews(News $news, CarbonImmutable $asOf, CarbonImmutable $windowStart): Collection
    {
        $threshold = (float) config('automation.news_scoring.coverage_similarity_threshold', 0.35);
        $targetText = $this->articleText($news);

        return News::query()
            ->with('source')
            ->whereKeyNot($news->getKey())
            ->whereNotNull('published_at')
            ->whereBetween('published_at', [$windowStart, $asOf])
            ->where('status', '!=', NewsStatus::Rejected->value)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(250)
            ->get()
            ->filter(fn (News $candidate): bool => $this->similarity->textSimilarity(
                $targetText,
                $this->articleText($candidate),
            ) >= $threshold)
            ->values();
    }

    /**
     * @return array{score: float, evidence: array<string, mixed>}
     */
    private function keywordHeat(News $news): array
    {
        $keywords = (array) config('automation.news_scoring.keyword_weights', []);
        $tokens = array_unique($this->similarity->tokenize(
            trim(($news->title ?? '').' '.($news->summary ?? '').' '.($news->content ?? '')),
        ));
        $tokenLookup = array_fill_keys($tokens, true);
        $matched = [];
        $score = 0.0;

        foreach ($keywords as $keyword => $weight) {
            $keyword = mb_strtolower(trim((string) $keyword));

            if ($keyword === '' || ! is_numeric($weight) || ! isset($tokenLookup[$keyword])) {
                continue;
            }

            $matched[] = $keyword;
            $score += max(0.0, (float) $weight);
        }

        return [
            'score' => $this->round(min(100.0, $score)),
            'evidence' => [
                'matched_keywords' => $matched,
                'matched_count' => count($matched),
            ],
        ];
    }

    /**
     * @return array{score: float, evidence: array<string, mixed>}
     */
    private function novelty(News $news, CarbonImmutable $asOf): array
    {
        $days = max(1, (int) config('automation.news_scoring.novelty_window_days', 7));
        $targetTokens = $this->similarity->tokenize($news->title ?? '');
        $maxSimilarity = 0.0;
        $nearestId = null;
        $compared = 0;

        $recent = News::query()
            ->whereKeyNot($news->getKey())
            ->whereNotNull('published_at')
            ->whereBetween('published_at', [$asOf->subDays($days), $asOf])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(500)
            ->get(['id', 'title']);

        foreach ($recent as $candidate) {
            $compared++;
            $similarity = $this->similarity->jaccardTokens(
                $targetTokens,
                $this->similarity->tokenize($candidate->title ?? ''),
            );

            if ($similarity > $maxSimilarity) {
                $maxSimilarity = $similarity;
                $nearestId = (int) $candidate->getKey();
            }
        }

        return [
            'score' => $this->round(max(0.0, (1.0 - $maxSimilarity) * 100.0)),
            'evidence' => [
                'window_days' => $days,
                'compared_news' => $compared,
                'nearest_news_id' => $nearestId,
                'max_similarity' => $this->round($maxSimilarity),
            ],
        ];
    }

    /**
     * @return array{score: float, evidence: array<string, mixed>}
     */
    private function recency(News $news, CarbonImmutable $asOf): array
    {
        $publishedAt = $news->published_at ?? $news->created_at ?? $asOf;
        $ageHours = max(0.0, ($asOf->getTimestamp() - $publishedAt->getTimestamp()) / 3600);
        $halfLife = max(1, (int) config('automation.news_scoring.recency_half_life_hours', 24));
        $score = 100.0 * (0.5 ** ($ageHours / $halfLife));

        return [
            'score' => $this->round($score),
            'evidence' => [
                'age_hours' => $this->round($ageHours),
                'half_life_hours' => $halfLife,
                'published_at' => $publishedAt->toIso8601String(),
            ],
        ];
    }

    /**
     * @return array<string, float>
     */
    private function weights(): array
    {
        $defaults = [
            'velocity' => 0.35,
            'keyword_heat' => 0.25,
            'novelty' => 0.20,
            'source_diversity' => 0.10,
            'recency' => 0.10,
        ];
        $configured = (array) config('automation.news_scoring.weights', []);
        $weights = [];

        foreach ($defaults as $key => $default) {
            $value = $configured[$key] ?? $default;
            $weights[$key] = is_numeric($value) && (float) $value >= 0
                ? (float) $value
                : (float) $default;
        }

        if (array_sum($weights) <= 0.0) {
            return $defaults;
        }

        return $weights;
    }

    /**
     * @return array{auto_greenlight_min: float, review_band_min: float}
     */
    private function thresholds(): array
    {
        $auto = (float) config(
            'automation.news_scoring.auto_greenlight_min',
            config('automation.news_scoring.min_auto_greenlight', 85),
        );
        // The production bands are fixed guardrails: auto-greenlight cannot
        // be configured below 85, and review begins between 60 and 84.
        $auto = min(100.0, max(85.0, $auto));
        $review = (float) config('automation.news_scoring.review_band_min', 60);
        $review = min(84.0, max(60.0, $review));
        $review = min($auto, $review);

        return [
            'auto_greenlight_min' => $auto,
            'review_band_min' => $review,
        ];
    }

    private function articleText(News $news): string
    {
        return trim(implode(' ', array_filter([
            (string) $news->title,
            (string) $news->summary,
            Str::limit((string) $news->content, 1200, ''),
        ])));
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

    private function round(float $value): float
    {
        return round($value, 2);
    }
}
