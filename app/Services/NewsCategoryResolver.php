<?php

namespace App\Services;

use App\Models\NewsCategory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Resolve RSS category hints into first-class news_categories records.
 *
 * Explicit article tags win over feed-level tags and content-only detection.
 * Existing categories are reused case-insensitively by name or slug; unknown
 * labels are created automatically, so a missing category never drops an
 * article.
 */
class NewsCategoryResolver
{
    /**
     * Hints that identify a feed/section rather than a useful topic. They are
     * still valid when nothing more specific exists.
     *
     * @var list<string>
     */
    private const GENERIC_HINTS = [
        'news',
        'latest',
        'featured',
        'general',
        'uncategorized',
        'tech',
        'technology',
        'top stories',
        'front page',
    ];

    /**
     * @var list<string>
     */
    private const COLORS = [
        '#3b82f6',
        '#8b5cf6',
        '#ec4899',
        '#f97316',
        '#22c55e',
        '#14b8a6',
        '#eab308',
        '#ef4444',
    ];

    /** @var array<string, NewsCategory> */
    private array $cache = [];

    /** @var array<string, string>|null */
    private ?array $aliasMap = null;

    /**
     * @param  list<string>  $itemCategories
     * @param  list<string>  $feedCategories
     */
    public function resolveArticle(
        array $itemCategories,
        string $title,
        ?string $summary = null,
        ?string $content = null,
        ?string $fallback = null,
        array $feedCategories = [],
        ?string $sourceName = null,
    ): NewsCategory {
        $itemHints = $this->rankHints($itemCategories, $sourceName);

        foreach (['specific', 'generic'] as $tier) {
            if (($itemHints[$tier][0] ?? null) !== null) {
                return $this->findOrCreate($itemHints[$tier][0]);
            }
        }

        $detected = $this->detectFromText($title, trim(($summary ?? '').' '.($content ?? '')));

        if ($detected !== null) {
            return $this->findOrCreate($detected);
        }

        $feedHints = $this->rankHints($feedCategories, $sourceName);

        foreach (['specific', 'generic'] as $tier) {
            if (($feedHints[$tier][0] ?? null) !== null) {
                return $this->findOrCreate($feedHints[$tier][0]);
            }
        }

        return $this->findOrCreate($fallback ?: (string) config('news_categories.fallback', 'Uncategorized'));
    }

    /**
     * Find an existing category by name/slug or create it. The lookup includes
     * the raw hint and its canonical alias target so existing taxonomies keep
     * their IDs.
     */
    public function findOrCreate(string $rawName): NewsCategory
    {
        $rawName = $this->sanitizeName($rawName) ?? 'Uncategorized';
        $canonical = $this->canonicalName($rawName);
        $cacheKey = $this->normalizeKey($canonical);

        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $candidates = array_values(array_unique(array_filter([
            $rawName,
            $canonical,
            $this->aliases()[$this->normalizeKey($rawName)] ?? null,
        ])));

        foreach ($candidates as $candidate) {
            $existing = $this->findExisting($candidate);

            if ($existing !== null) {
                return $this->remember($existing, $candidates, $canonical);
            }
        }

        $slug = $this->uniqueSlug($canonical);

        try {
            $category = NewsCategory::query()->create([
                'name' => $canonical,
                'slug' => $slug,
                'description' => null,
                'color' => $this->colorFor($slug),
                'icon' => null,
                'is_active' => true,
            ]);
        } catch (QueryException $exception) {
            // A parallel feed import may have created the same slug first.
            $category = $this->findExisting($canonical)
                ?? NewsCategory::query()->where('slug', $slug)->first();

            if ($category === null) {
                throw $exception;
            }
        }

        return $this->remember($category, $candidates, $canonical);
    }

    /**
     * Detect the strongest category from article text. Only used when no
     * explicit article-level tag is present.
     */
    public function detectFromText(string $title, ?string $body = null): ?string
    {
        $titleText = $this->plainText($title);
        $bodyText = mb_substr($this->plainText($body ?? ''), 0, 8000);

        if ($titleText === '' && $bodyText === '') {
            return null;
        }

        $best = null;
        $bestScore = 0.0;

        foreach ((array) config('news_categories.detectors', []) as $category => $terms) {
            if (! is_array($terms)) {
                continue;
            }

            $score = 0.0;

            foreach ($terms as $term => $weight) {
                $weight = (float) $weight;
                $term = $this->normalizeKey((string) $term);

                if ($term === '' || $weight <= 0.0) {
                    continue;
                }

                $pattern = '~(?<![\p{L}\p{N}])'.preg_quote($term, '~').'(?![\p{L}\p{N}])~iu';
                $titleHits = (int) preg_match_all($pattern, $titleText);
                $bodyHits = (int) preg_match_all($pattern, $bodyText);

                if ($titleHits > 0) {
                    $score += $weight * 2.0 * min($titleHits, 2);
                }

                if ($bodyHits > 0) {
                    $score += $weight * min($bodyHits, 3);
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = (string) $category;
            }
        }

        $minimum = max(0.1, (float) config('news_categories.detection_min_score', 2.0));

        return $bestScore >= $minimum ? $best : null;
    }

    /**
     * @param  list<string>  $hints
     * @return array{specific: list<string>, generic: list<string>}
     */
    private function rankHints(array $hints, ?string $sourceName): array
    {
        $specific = [];
        $generic = [];
        $sourceKey = $sourceName !== null ? $this->normalizeKey($sourceName) : null;

        foreach ($hints as $hint) {
            $name = $this->sanitizeName((string) $hint);

            if ($name === null) {
                continue;
            }

            $key = $this->normalizeKey($name);

            if ($sourceKey !== null && $key === $sourceKey) {
                continue;
            }

            $canonical = $this->canonicalName($name);
            $canonicalKey = $this->normalizeKey($canonical);

            if (in_array($canonicalKey, self::GENERIC_HINTS, true) || in_array($key, self::GENERIC_HINTS, true)) {
                if (! in_array($name, $generic, true)) {
                    $generic[] = $name;
                }

                continue;
            }

            if (! in_array($name, $specific, true)) {
                $specific[] = $name;
            }
        }

        return ['specific' => $specific, 'generic' => $generic];
    }

    private function canonicalName(string $rawName): string
    {
        $name = $this->sanitizeName($rawName) ?? 'Uncategorized';
        $alias = $this->aliases()[$this->normalizeKey($name)] ?? null;
        $name = $alias ?? $name;

        // Preserve acronyms (AI, PHP, API); title-case ordinary lowercase tags.
        if ($name === mb_strtolower($name)) {
            $name = Str::title($name);
        }

        return Str::limit($name, 80, '');
    }

    private function sanitizeName(string $name): ?string
    {
        $name = html_entity_decode(strip_tags($name), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = preg_replace('/^\s*(category|tag|topic)\s*[:\-]\s*/iu', '', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = trim($name, " \t\n\r\0\x0B#|,;:-");

        if ($name === '' || mb_strlen($name) > 80) {
            return null;
        }

        if (preg_match('~^https?://~iu', $name) === 1) {
            return null;
        }

        if (preg_match('~^[\d\W]+$~u', $name) === 1) {
            return null;
        }

        return $name;
    }

    private function normalizeKey(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = preg_replace('/[\s_\-\/]+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function plainText(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\s_\-\/]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return Str::lower(trim($value));
    }

    /**
     * @return array<string, string>
     */
    private function aliases(): array
    {
        if ($this->aliasMap !== null) {
            return $this->aliasMap;
        }

        $this->aliasMap = [];

        foreach ((array) config('news_categories.aliases', []) as $alias => $canonical) {
            if (! is_string($alias) || ! is_string($canonical) || trim($canonical) === '') {
                continue;
            }

            $this->aliasMap[$this->normalizeKey($alias)] = $canonical;
        }

        return $this->aliasMap;
    }

    private function findExisting(string $name): ?NewsCategory
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        return NewsCategory::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->orWhere('slug', Str::slug($name))
            ->first();
    }

    private function uniqueSlug(string $name): string
    {
        $baseSlug = Str::slug($name);
        $baseSlug = $baseSlug !== '' ? $baseSlug : 'category';
        $slug = $baseSlug;
        $suffix = 2;

        while (NewsCategory::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix++;
        }

        return $slug;
    }

    private function colorFor(string $slug): string
    {
        $index = abs((int) crc32(mb_strtolower($slug))) % count(self::COLORS);

        return self::COLORS[$index];
    }

    /**
     * @param  list<string>  $candidates
     */
    private function remember(NewsCategory $category, array $candidates, string $canonical): NewsCategory
    {
        $this->cache[$this->normalizeKey($canonical)] = $category;

        foreach ($candidates as $candidate) {
            $this->cache[$this->normalizeKey($candidate)] = $category;
        }

        return $category;
    }
}
