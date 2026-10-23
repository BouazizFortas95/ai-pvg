<?php

namespace App\Services;

use App\Models\News;
use Illuminate\Support\Str;

class NewsDuplicateService
{
    /**
     * Entry point for future RSS import jobs: detect an already-imported
     * article for the given source URL and/or source + title pair.
     *
     * Detection rules (in order):
     * 1. An exact match on the normalized source URL (any source).
     * 2. The same source and the same normalized title.
     *
     * `null` URL / null source combinations never match anything, which
     * prevents false positives between unrelated sources.
     */
    public function findExistingNews(
        ?string $sourceUrl = null,
        ?string $title = null,
        ?int $newsSourceId = null,
        ?int $exceptNewsId = null,
    ): ?News {
        return $this->findBySourceUrl($sourceUrl, $exceptNewsId)
            ?? $this->findBySourceAndTitle($title, $newsSourceId, $exceptNewsId);
    }

    /**
     * Find an existing article by its normalized source URL.
     */
    public function findBySourceUrl(?string $sourceUrl, ?int $exceptNewsId = null): ?News
    {
        $normalizedUrl = static::normalizeUrl($sourceUrl);

        if ($normalizedUrl === null) {
            return null;
        }

        return News::query()
            ->where('normalized_source_url', $normalizedUrl)
            ->when($exceptNewsId !== null, fn ($query) => $query->whereKeyNot($exceptNewsId))
            ->orderBy('id')
            ->first();
    }

    /**
     * Find an existing article carrying the same normalized title
     * within the same news source. Different sources with identical
     * titles are never considered duplicates.
     */
    public function findBySourceAndTitle(?string $title, ?int $newsSourceId, ?int $exceptNewsId = null): ?News
    {
        $normalizedTitle = static::normalizeTitle($title);

        if ($normalizedTitle === null || $newsSourceId === null) {
            return null;
        }

        return News::query()
            ->where('news_source_id', $newsSourceId)
            ->where('normalized_title', $normalizedTitle)
            ->when($exceptNewsId !== null, fn ($query) => $query->whereKeyNot($exceptNewsId))
            ->orderBy('id')
            ->first();
    }

    /**
     * Normalize a URL so that cosmetic differences (scheme, "www.",
     * default ports, trailing slash, tracking parameters, parameter
     * order, letter case) do not create false duplicates.
     *
     * URLs that are empty or cannot be parsed are returned as `null`,
     * so callers can never match on an empty value.
     */
    public static function normalizeUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        $hasScheme = str_starts_with(strtolower($url), 'http://')
            || str_starts_with(strtolower($url), 'https://');

        if (! $hasScheme) {
            $url = 'https://'.$url;
        }

        $parsed = parse_url($url);

        $scheme = strtolower($parsed['scheme'] ?? '');
        $host = strtolower($parsed['host'] ?? '');
        $port = $parsed['port'] ?? null;
        $path = $parsed['path'] ?? '/';
        $query = $parsed['query'] ?? null;

        if ($host === '') {
            return null;
        }

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        // Implicit-scheme URLs receive "https" above; treat http and
        // https as equivalent for deduplication purposes.
        $scheme = 'https';

        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        parse_str((string) $query, $parameters);

        $parameters = collect($parameters)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->reject(fn ($value, $key) => static::isTrackingParameter((string) $key))
            ->sortKeys()
            ->all();

        $query = http_build_query($parameters);

        return implode('', [
            $scheme.'://',
            $host,
            $port !== null ? ':'.$port : '',
            $path === '' ? '/' : $path,
            filled($query) ? '?'.$query : '',
        ]);
    }

    /**
     * Normalize a title for comparison: lowercase, single-spaced.
     */
    public static function normalizeTitle(?string $title): ?string
    {
        $title = trim((string) $title);

        if ($title === '') {
            return null;
        }

        return Str::of($title)
            ->squish()
            ->lower()
            ->toString();
    }

    /**
     * Common analytics/tracking keys that must not influence matching.
     */
    protected static function isTrackingParameter(string $key): bool
    {
        if (Str::startsWith($key, 'utm_')) {
            return true;
        }

        return in_array(Str::lower($key), [
            'fbclid',
            'gclid',
            'mc_cid',
            'mc_eid',
            'ref',
            'ref_src',
            'source',
        ]);
    }
}
