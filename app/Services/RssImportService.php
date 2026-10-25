<?php

namespace App\Services;

use App\Enums\NewsStatus;
use App\Models\News;
use App\Models\NewsSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class RssImportService
{
    /**
     * Import a feed.
     *
     * Optional controls keep the legacy job behavior unchanged while letting
     * bounded operator commands set a category and mark imported items ready:
     *
     * - limit: stop after this many successful inserts
     * - status: NewsStatus value for inserted articles (default: draft)
     * - news_category_id: structured category foreign key
     * - category: legacy category label (default: Uncategorized)
     *
     * @param  array{limit?: int|null, status?: string|null, news_category_id?: int|null, category?: string|null}  $options
     */
    public function import(NewsSource $source, array $options = []): ImportResult
    {
        $result = new ImportResult($source);

        if (! $this->isSafeUrl($source->rss_url)) {
            $result->errors[] = 'RSS URL blocked by security policy (SSRF or private IP).';
            $result->failedItems++;
            $result->complete();

            return $result;
        }

        try {
            $response = Http::withOptions([
                'timeout' => 30,
                'connect_timeout' => 10,
                'allow_redirects' => ['max' => 3],
            ])->get($source->rss_url);
        } catch (\Exception $e) {
            $result->errors[] = 'HTTP fetch failed: '.$e->getMessage();
            $result->failedItems++;
            $result->complete();

            return $result;
        }

        if (! $response->successful()) {
            $result->errors[] = 'Feed returned HTTP '.$response->status().'.';
            $result->failedItems++;
            $result->complete();

            return $result;
        }

        $xmlString = (string) $response->body();
        $items = $this->parseFeed($xmlString, $result);
        $result->totalItems = count($items);

        $limit = isset($options['limit']) ? max(0, (int) $options['limit']) : null;
        $status = NewsStatus::tryFrom((string) ($options['status'] ?? '')) ?? NewsStatus::Draft;
        $categoryId = isset($options['news_category_id']) ? (int) $options['news_category_id'] : null;
        $categoryLabel = trim((string) ($options['category'] ?? '')) ?: __('Uncategorized');

        foreach ($items as $item) {
            if ($limit !== null && $result->importedItems >= $limit) {
                break;
            }

            $this->processItem($item, $source, $result, $status, $categoryId, $categoryLabel);
        }

        $result->complete();

        return $result;
    }

    protected function isSafeUrl(?string $url): bool
    {
        if (empty($url)) {
            return false;
        }
        $parsed = parse_url($url);
        if (! isset($parsed['host'])) {
            return false;
        }
        $host = strtolower($parsed['host']);
        if ($host === 'localhost' || $host === '127.0.0.1' || $host === '0.0.0.0' || $host === '::1') {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (preg_match('/^\d+\.\d+\.\d+\.\d+$/', $host)) {
            return false;
        }

        return true;
    }

    protected function parseFeed(string $xmlString, ImportResult $result): array
    {
        libxml_use_internal_errors(true);
        $entityLoader = libxml_disable_entity_loader(true);
        $sxml = simplexml_load_string($xmlString, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT);
        libxml_disable_entity_loader($entityLoader);
        libxml_clear_errors();

        if ($sxml === false) {
            $result->errors[] = 'Invalid XML feed.';
            $result->failedItems++;

            return [];
        }

        $items = [];
        if (isset($sxml->channel)) {
            foreach ($sxml->channel->item as $item) {
                $items[] = $this->extractRssItem($item);
            }
        }
        if (isset($sxml->entry)) {
            foreach ($sxml->entry as $entry) {
                $items[] = $this->extractAtomEntry($entry);
            }
        }

        return $items;
    }

    protected function extractRssItem(\SimpleXMLElement $item): array
    {
        return [
            'title' => trim((string) ($item->title ?? '')),
            'url' => trim((string) ($item->link ?? '')),
            'description' => trim((string) ($item->description ?? $item->summary ?? '')),
            'content' => trim((string) ($item->content ?? '')),
            'published_at' => trim((string) ($item->pubDate ?? '')),
            'image_url' => trim((string) ($item->media ?? '')),
        ];
    }

    protected function extractAtomEntry(\SimpleXMLElement $entry): array
    {
        $content = '';
        if (isset($entry->content)) {
            $content = (string) $entry->content;
            if (isset($entry->content['type']) && (string) $entry->content['type'] === 'html') {
                $content = (string) $entry->content;
            }
        }
        $url = '';
        foreach ($entry->link as $link) {
            $rel = (string) ($link['rel'] ?? 'alternate');
            $href = (string) ($link['href'] ?? '');
            if ($rel === 'alternate' && $href !== '') {
                $url = $href;
                break;
            }
        }
        $imageUrl = '';
        if (isset($entry->logo)) {
            $imageUrl = (string) $entry->logo;
        }
        if (empty($imageUrl) && isset($entry->media)) {
            foreach ($entry->media as $media) {
                $url = (string) ($media['url'] ?? '');
                if ($url !== '') {
                    $imageUrl = $url;
                    break;
                }
            }
        }

        return [
            'title' => trim((string) ($entry->title ?? '')),
            'url' => $url,
            'description' => trim((string) ($entry->summary ?? $entry->subtitle ?? '')),
            'content' => $content,
            'published_at' => trim((string) ($entry->published ?? $entry->updated ?? '')),
            'image_url' => $imageUrl,
        ];
    }

    protected function processItem(
        array $item,
        NewsSource $source,
        ImportResult $result,
        NewsStatus $status,
        ?int $categoryId,
        string $categoryLabel,
    ): void {
        if (empty($item['title'])) {
            $result->skippedItems++;

            return;
        }
        if (empty($item['url'])) {
            $item['url'] = null;
        }

        $normalizedUrl = NewsDuplicateService::normalizeUrl($item['url']);
        $normalizedTitle = NewsDuplicateService::normalizeTitle($item['title']);

        $existing = app(NewsDuplicateService::class)->findExistingNews($normalizedUrl, $normalizedTitle, $source->getKey());
        if ($existing !== null) {
            $result->duplicateItems++;

            return;
        }

        $slug = Str::slug($item['title']);
        $attempts = 0;
        while (News::query()->where('slug', $slug)->exists() && $attempts < 10) {
            $slug = $slug.'-'.(++$attempts);
        }

        $summary = $this->sanitizeHtml($item['description'] ?? $item['content'] ?? '');
        $content = $this->sanitizeHtml($item['content'] ?? $item['description'] ?? '');

        try {
            News::create([
                'title' => $item['title'],
                'slug' => $slug,
                'summary' => $summary,
                'content' => $content,
                'source_url' => $item['url'],
                'source_name' => $source->name,
                'image' => $item['image_url'],
                'category' => $categoryLabel,
                'status' => $status->value,
                'published_at' => $this->parseDate($item['published_at']),
                'news_category_id' => $categoryId,
                'news_source_id' => $source->getKey(),
                'normalized_source_url' => $normalizedUrl,
                'normalized_title' => $normalizedTitle,
            ]);
            $result->importedItems++;
        } catch (\Exception $e) {
            $result->failedItems++;
            $result->errors[] = 'Failed to save article "'.$item['title'].'": '.$e->getMessage();
        }
    }

    protected function sanitizeHtml(?string $html): ?string
    {
        if ($html === null || $html === '') {
            return null;
        }
        $allowedTags = '<p><br><a><b><strong><i><em><u><ul><ol><li><h1><h2><h3><h4><h5><h6><blockquote><pre><code><img>';

        return trim(strip_tags($html, $allowedTags));
    }

    protected function parseDate(?string $dateStr): ?\DateTime
    {
        if (empty($dateStr)) {
            return null;
        }
        try {
            return new \DateTime($dateStr);
        } catch (\Exception $e) {
            return null;
        }
    }
}
