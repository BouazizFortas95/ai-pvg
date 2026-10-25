<?php

namespace App\Services;

use App\Enums\NewsStatus;
use App\Models\News;
use App\Models\NewsCategory;
use App\Models\NewsSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class RssImportService
{
    public function __construct(private readonly NewsCategoryResolver $categories) {}

    /**
     * Import a feed.
     *
     * Optional controls:
     *
     * - limit: stop after this many successful inserts
     * - status: NewsStatus value for inserted articles (default: draft)
     * - news_category_id: explicit category override; when omitted, article
     *   tags/feed categories/content are resolved automatically
     * - category: fallback label used only when no category can be detected
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
        $overrideCategory = $categoryId !== null ? NewsCategory::query()->find($categoryId) : null;
        $fallbackCategory = trim((string) ($options['category'] ?? ''))
            ?: (string) config('news_categories.fallback', __('Uncategorized'));

        foreach ($items as $item) {
            if ($limit !== null && $result->importedItems >= $limit) {
                break;
            }

            $this->processItem($item, $source, $result, $status, $overrideCategory, $fallbackCategory);
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
            $feedCategories = $this->extractCategories($sxml->channel);

            foreach ($sxml->channel->item as $item) {
                $items[] = $this->extractRssItem($item, $feedCategories);
            }
        }
        if (isset($sxml->entry)) {
            $feedCategories = $this->extractCategories($sxml);

            foreach ($sxml->entry as $entry) {
                $items[] = $this->extractAtomEntry($entry, $feedCategories);
            }
        }

        return $items;
    }

    /**
     * @param  list<string>  $feedCategories
     */
    protected function extractRssItem(\SimpleXMLElement $item, array $feedCategories = []): array
    {
        $contentNamespace = $item->children('http://purl.org/rss/1.0/modules/content/');
        $encodedContent = trim((string) ($contentNamespace->encoded ?? ''));

        return [
            'title' => trim((string) ($item->title ?? '')),
            'url' => trim((string) ($item->link ?? '')),
            'description' => trim((string) ($item->description ?? $item->summary ?? '')),
            'content' => $encodedContent !== '' ? $encodedContent : trim((string) ($item->content ?? '')),
            'published_at' => trim((string) ($item->pubDate ?? '')),
            'image_url' => trim((string) ($item->media ?? '')),
            'categories' => $this->extractCategories($item),
            'feed_categories' => $feedCategories,
        ];
    }

    /**
     * @param  list<string>  $feedCategories
     */
    protected function extractAtomEntry(\SimpleXMLElement $entry, array $feedCategories = []): array
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
            'categories' => $this->extractCategories($entry),
            'feed_categories' => $feedCategories,
        ];
    }

    /**
     * Read RSS/Atom category elements plus Dublin Core subjects and MRSS
     * keywords. These are hints only; the resolver owns canonicalization.
     *
     * @return list<string>
     */
    protected function extractCategories(\SimpleXMLElement $node): array
    {
        $categories = [];

        foreach ($node->category as $category) {
            $label = trim((string) ($category['label'] ?? ''));
            $term = trim((string) ($category['term'] ?? ''));
            $value = $label !== '' ? $label : ($term !== '' ? $term : trim((string) $category));

            $this->pushCategoryValue($categories, $value);
        }

        $dublinCore = $node->children('http://purl.org/dc/elements/1.1/');

        foreach ($dublinCore->subject as $subject) {
            $this->pushCategoryValue($categories, (string) $subject);
        }

        $media = $node->children('http://search.yahoo.com/mrss/');

        if (isset($media->keywords)) {
            foreach (preg_split('/[,|]/', (string) $media->keywords) ?: [] as $keyword) {
                $this->pushCategoryValue($categories, $keyword);
            }
        }

        return array_values(array_unique($categories));
    }

    /**
     * @param  list<string>  $categories
     */
    protected function pushCategoryValue(array &$categories, string $value): void
    {
        foreach (preg_split('/[|;]/', $value) ?: [] as $part) {
            $part = trim(html_entity_decode(strip_tags($part), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($part !== '') {
                $categories[] = $part;
            }
        }
    }

    protected function processItem(
        array $item,
        NewsSource $source,
        ImportResult $result,
        NewsStatus $status,
        ?NewsCategory $overrideCategory,
        string $fallbackCategory,
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
            $category = $overrideCategory ?? $this->categories->resolveArticle(
                itemCategories: $item['categories'] ?? [],
                title: (string) $item['title'],
                summary: $summary,
                content: $content,
                fallback: $fallbackCategory,
                feedCategories: $item['feed_categories'] ?? [],
                sourceName: (string) $source->name,
            );

            News::create([
                'title' => $item['title'],
                'slug' => $slug,
                'summary' => $summary,
                'content' => $content,
                'source_url' => $item['url'],
                'source_name' => $source->name,
                'image' => $item['image_url'],
                'category' => $category->slug ?: (Str::slug((string) $category->name) ?: 'uncategorized'),
                'status' => $status->value,
                'published_at' => $this->parseDate($item['published_at']),
                'news_category_id' => $category->getKey(),
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
