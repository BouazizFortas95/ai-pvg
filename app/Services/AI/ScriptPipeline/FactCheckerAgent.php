<?php

namespace App\Services\AI\ScriptPipeline;

use App\Enums\ScriptFactCheckVerdict;
use App\Services\Automation\Clustering\TextSimilarityService;
use Illuminate\Support\Str;

/**
 * Zero-hallucination gate. Verdicts are deterministic: a claim is supported
 * only when its explicit member news ids contain matching evidence text.
 * There is no AI call here, so fact checking cannot invent its own evidence.
 */
class FactCheckerAgent
{
    public function __construct(private readonly TextSimilarityService $similarity) {}

    /**
     * @param  list<array<string, mixed>>  $scenes
     * @param  list<array{id: int, title: string, summary: ?string, content: ?string, source_name: ?string, source_url: ?string}>  $news
     * @param  array<string, mixed>  $factSheet
     * @param  list<array{claim: string, source_news_ids: list<int>}>  $extraClaims
     * @return list<array{
     *     claim: string,
     *     claim_hash: string,
     *     verdict: ScriptFactCheckVerdict,
     *     source_news_ids: list<int>,
     *     evidence: string,
     *     evidence_news_id: int|null,
     *     critic_score: float,
     *     coverage: float,
     *     reason: string
     * }>
     */
    public function check(array $scenes, array $news, array $factSheet, array $extraClaims = []): array
    {
        $byId = [];
        $validIds = [];

        foreach ($news as $item) {
            $id = (int) $item['id'];
            $validIds[] = $id;
            $byId[$id] = $item;
        }

        $validIds = array_values(array_unique($validIds));
        $factClaims = $this->factClaims($factSheet);
        $claims = $this->collectClaims($scenes, $extraClaims);
        $results = [];

        foreach ($claims as $claim) {
            $text = trim((string) $claim['claim']);
            $normalized = $this->normalize($text);

            if ($normalized === '') {
                continue;
            }

            $sourceIds = array_values(array_unique(array_map('intval', $claim['source_news_ids'])));

            if ($sourceIds === []) {
                $results[] = $this->result(
                    claim: $text,
                    verdict: ScriptFactCheckVerdict::Uncertain,
                    sourceIds: [],
                    evidence: '',
                    evidenceNewsId: null,
                    coverage: 0.0,
                    reason: 'No source_news_ids were supplied for this claim.',
                );

                continue;
            }

            $invalid = array_values(array_diff($sourceIds, $validIds));

            if ($invalid !== []) {
                $results[] = $this->result(
                    claim: $text,
                    verdict: ScriptFactCheckVerdict::Unsupported,
                    sourceIds: $sourceIds,
                    evidence: '',
                    evidenceNewsId: null,
                    coverage: 0.0,
                    reason: 'Source ids are not members of the grounded story: '.implode(', ', $invalid).'.',
                );

                continue;
            }

            [$evidence, $evidenceNewsId, $coverage] = $this->bestEvidence($text, $sourceIds, $byId);
            $exact = isset($factClaims[$normalized])
                && array_intersect($factClaims[$normalized], $sourceIds) !== [];
            $threshold = max(0.0, min(1.0, (float) config(
                'automation.script_agents.claim_coverage_threshold',
                0.30,
            )));

            if ($exact || $coverage >= $threshold) {
                $verdict = ScriptFactCheckVerdict::Supported;
                $reason = $exact
                    ? 'Claim matches a grounded fact-sheet claim.'
                    : 'Claim tokens are covered by the cited member news evidence.';
            } elseif ($coverage > 0.0) {
                $verdict = ScriptFactCheckVerdict::Uncertain;
                $reason = 'Only partial lexical support was found in the cited member news.';
            } else {
                $verdict = ScriptFactCheckVerdict::Unsupported;
                $reason = 'No supporting text was found in the cited member news.';
            }

            $results[] = $this->result(
                claim: $text,
                verdict: $verdict,
                sourceIds: $sourceIds,
                evidence: $this->snippet($evidence, $evidenceNewsId, $byId),
                evidenceNewsId: $evidenceNewsId,
                coverage: $coverage,
                reason: $reason,
            );
        }

        return $results;
    }

    /**
     * @param  list<array<string, mixed>>  $scenes
     * @param  list<array{claim: string, source_news_ids: list<int>}>  $extraClaims
     * @return list<array{claim: string, source_news_ids: list<int>}>
     */
    private function collectClaims(array $scenes, array $extraClaims): array
    {
        $claims = [];

        foreach ($extraClaims as $claim) {
            if (! is_array($claim) || ! isset($claim['claim'])) {
                continue;
            }

            $this->mergeClaim($claims, (string) $claim['claim'], (array) ($claim['source_news_ids'] ?? []));
        }

        foreach ($scenes as $scene) {
            if (! is_array($scene)) {
                continue;
            }

            $text = $scene['claim'] ?? $scene['narration'] ?? $scene['headline'] ?? null;

            if (! is_string($text) || trim($text) === '') {
                continue;
            }

            $this->mergeClaim($claims, $text, (array) ($scene['source_news_ids'] ?? []));
        }

        return $claims;
    }

    /**
     * @param  array<string, array{claim: string, source_news_ids: list<int>}>  $claims
     * @param  array<int, mixed>  $sourceIds
     */
    private function mergeClaim(array &$claims, string $text, array $sourceIds): void
    {
        $normalized = $this->normalize($text);

        if ($normalized === '') {
            return;
        }

        $ids = array_values(array_unique(array_map('intval', $sourceIds)));

        if (! isset($claims[$normalized])) {
            $claims[$normalized] = ['claim' => trim($text), 'source_news_ids' => $ids];

            return;
        }

        $claims[$normalized]['source_news_ids'] = array_values(array_unique(array_merge(
            $claims[$normalized]['source_news_ids'],
            $ids,
        )));
    }

    /**
     * @param  array<string, mixed>  $factSheet
     * @return array<string, list<int>>
     */
    private function factClaims(array $factSheet): array
    {
        $claims = [];

        foreach ((array) ($factSheet['claims'] ?? []) as $claim) {
            if (! is_array($claim) || ! isset($claim['claim']) || ! is_string($claim['claim'])) {
                continue;
            }

            $normalized = $this->normalize($claim['claim']);

            if ($normalized === '') {
                continue;
            }

            $claims[$normalized] = array_values(array_unique(array_merge(
                $claims[$normalized] ?? [],
                array_map('intval', (array) ($claim['source_news_ids'] ?? [])),
            )));
        }

        return $claims;
    }

    /**
     * @param  list<int>  $sourceIds
     * @param  array<int, array{id: int, title: string, summary: ?string, content: ?string, source_name: ?string, source_url: ?string}>  $byId
     * @return array{0: string, 1: int|null, 2: float}
     */
    private function bestEvidence(string $claim, array $sourceIds, array $byId): array
    {
        $claimTokens = $this->similarity->tokenize($claim);
        $best = '';
        $bestId = null;
        $bestCoverage = 0.0;

        foreach ($sourceIds as $id) {
            $item = $byId[$id] ?? null;

            if ($item === null) {
                continue;
            }

            $text = trim(implode(' ', array_filter([
                (string) $item['title'],
                $item['summary'] ?? '',
                $item['content'] ?? '',
            ])));
            $evidenceTokens = $this->similarity->tokenize($text);
            $coverage = $this->coverage($claimTokens, $evidenceTokens);

            if ($bestId === null || $coverage > $bestCoverage) {
                $best = $text;
                $bestId = $id;
                $bestCoverage = $coverage;
            }
        }

        return [$best, $bestId, $bestCoverage];
    }

    /**
     * @param  list<string>  $claimTokens
     * @param  list<string>  $evidenceTokens
     */
    private function coverage(array $claimTokens, array $evidenceTokens): float
    {
        $claimTokens = array_values(array_unique($claimTokens));

        if ($claimTokens === []) {
            return 0.0;
        }

        $overlap = count(array_intersect($claimTokens, array_values(array_unique($evidenceTokens))));

        return max(0.0, min(1.0, $overlap / count($claimTokens)));
    }

    /**
     * @param  array<int, array{id: int, title: string, summary: ?string, content: ?string, source_name: ?string, source_url: ?string}>  $byId
     */
    private function snippet(string $evidence, ?int $evidenceNewsId, array $byId): string
    {
        if ($evidence === '' || $evidenceNewsId === null) {
            return 'No supporting source text found.';
        }

        $max = max(80, (int) config('automation.script_agents.evidence_max_chars', 300));
        $item = $byId[$evidenceNewsId] ?? null;
        $prefix = $item !== null && is_string($item['source_name']) && $item['source_name'] !== ''
            ? $item['source_name'].': '
            : 'News #'.$evidenceNewsId.': ';

        return Str::limit($prefix.$evidence, $max, '…');
    }

    /**
     * @param  list<int>  $sourceIds
     * @return array{
     *     claim: string,
     *     claim_hash: string,
     *     verdict: ScriptFactCheckVerdict,
     *     source_news_ids: list<int>,
     *     evidence: string,
     *     evidence_news_id: int|null,
     *     critic_score: float,
     *     coverage: float,
     *     reason: string
     * }
     */
    private function result(
        string $claim,
        ScriptFactCheckVerdict $verdict,
        array $sourceIds,
        string $evidence,
        ?int $evidenceNewsId,
        float $coverage,
        string $reason,
    ): array {
        $critic = match ($verdict) {
            ScriptFactCheckVerdict::Supported => 80.0 + ($coverage * 20.0),
            ScriptFactCheckVerdict::Uncertain => 40.0 + ($coverage * 40.0),
            ScriptFactCheckVerdict::Unsupported => max(0.0, $coverage * 40.0),
        };

        return [
            'claim' => $claim,
            'claim_hash' => hash('sha256', $this->normalize($claim)),
            'verdict' => $verdict,
            'source_news_ids' => $sourceIds,
            'evidence' => $evidence,
            'evidence_news_id' => $evidenceNewsId,
            'critic_score' => round(max(0.0, min(100.0, $critic)), 2),
            'coverage' => round($coverage, 4),
            'reason' => $reason,
        ];
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));

        return trim((string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text));
    }
}
