<?php

namespace App\Services\Automation\Clustering;

/**
 * Deterministic, offline token and TF-IDF similarity helpers. No provider,
 * HTTP request, or paid embedding call is ever made by this service.
 */
class TextSimilarityService
{
    /**
     * Small multilingual stop-word set covering the supported news languages.
     *
     * @var array<string, true>
     */
    private const STOPWORDS = [
        'the' => true, 'and' => true, 'for' => true, 'with' => true, 'from' => true,
        'that' => true, 'this' => true, 'are' => true, 'was' => true, 'were' => true,
        'has' => true, 'have' => true, 'had' => true, 'its' => true, 'into' => true,
        'about' => true, 'after' => true, 'over' => true, 'under' => true, 'will' => true,
        'you' => true, 'your' => true, 'our' => true, 'their' => true, 'his' => true,
        'her' => true, 'not' => true, 'but' => true, 'all' => true, 'any' => true,
        'can' => true, 'new' => true, 'now' => true, 'says' => true, 'said' => true,
        'le' => true, 'la' => true, 'les' => true, 'des' => true, 'du' => true,
        'de' => true, 'et' => true, 'en' => true, 'un' => true, 'une' => true,
        'pour' => true, 'avec' => true, 'dans' => true, 'sur' => true, 'par' => true,
        'في' => true, 'من' => true, 'على' => true, 'إلى' => true, 'عن' => true,
        'أن' => true, 'هذا' => true, 'هذه' => true, 'التي' => true, 'الذي' => true,
        'مع' => true, 'كان' => true, 'تم' => true,
    ];

    /**
     * @return list<string>
     */
    public function tokenize(?string $text): array
    {
        $text = strip_tags(html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];
        $tokens = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '' || mb_strlen($part) < 2 || isset(self::STOPWORDS[$part])) {
                continue;
            }

            $tokens[] = $part;
        }

        return $tokens;
    }

    /**
     * Build tokens for an article, weighting the title by repeating it.
     *
     * @return list<string>
     */
    public function documentTokens(string $title, ?string $summary, ?string $content): array
    {
        $titleTokens = $this->tokenize($title);
        $body = $this->tokenize(trim(($summary ?? '').' '.mb_substr((string) $content, 0, 2000)));

        return array_merge($titleTokens, $titleTokens, $body);
    }

    /**
     * @param  array<int|string, list<string>>  $documents
     * @return array<int|string, array<string, float>>
     */
    public function tfidfVectors(array $documents): array
    {
        $documentCount = max(1, count($documents));
        $documentFrequency = [];

        foreach ($documents as $tokens) {
            foreach (array_unique($tokens) as $term) {
                $documentFrequency[$term] = ($documentFrequency[$term] ?? 0) + 1;
            }
        }

        $idf = [];

        foreach ($documentFrequency as $term => $frequency) {
            $idf[$term] = log(($documentCount + 1) / ($frequency + 1)) + 1.0;
        }

        $vectors = [];

        foreach ($documents as $key => $tokens) {
            $vectors[$key] = $this->vector($tokens, $idf);
        }

        return $vectors;
    }

    /**
     * @param  list<string>  $tokens
     * @param  array<string, float>  $idf
     * @return array<string, float>
     */
    public function vector(array $tokens, array $idf): array
    {
        if ($tokens === []) {
            return [];
        }

        $termFrequency = array_count_values($tokens);
        $vector = [];

        foreach ($termFrequency as $term => $count) {
            $vector[$term] = (float) $count * ($idf[$term] ?? 1.0);
        }

        $norm = sqrt(array_sum(array_map(fn (float $value): float => $value * $value, $vector)));

        if ($norm <= 0.0) {
            return [];
        }

        foreach ($vector as $term => $value) {
            $vector[$term] = $value / $norm;
        }

        return $vector;
    }

    /**
     * @param  array<string, float>  $left
     * @param  array<string, float>  $right
     */
    public function cosine(array $left, array $right): float
    {
        if ($left === [] || $right === []) {
            return 0.0;
        }

        $dot = 0.0;

        if (count($left) > count($right)) {
            [$left, $right] = [$right, $left];
        }

        foreach ($left as $term => $value) {
            if (isset($right[$term])) {
                $dot += $value * $right[$term];
            }
        }

        return max(0.0, min(1.0, $dot));
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     */
    public function jaccardTokens(array $left, array $right): float
    {
        $left = array_values(array_unique($left));
        $right = array_values(array_unique($right));

        if ($left === [] || $right === []) {
            return 0.0;
        }

        $intersection = count(array_intersect($left, $right));
        $union = count(array_unique(array_merge($left, $right)));

        return $union === 0 ? 0.0 : $intersection / $union;
    }

    public function textSimilarity(?string $left, ?string $right): float
    {
        return $this->jaccardTokens($this->tokenize($left), $this->tokenize($right));
    }
}
