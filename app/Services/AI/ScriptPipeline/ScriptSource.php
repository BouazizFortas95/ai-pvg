<?php

namespace App\Services\AI\ScriptPipeline;

/**
 * Immutable, grounded input for the G-03 agent chain. Every claim carries
 * explicit member news ids; no free-floating text is accepted downstream.
 */
final readonly class ScriptSource
{
    /**
     * @param  list<array{id: int, title: string, summary: ?string, content: ?string, source_name: ?string, source_url: ?string}>  $news
     * @param  list<array{claim: string, source_news_ids: list<int>}>  $claims
     * @param  array<string, mixed>  $factSheet
     */
    public function __construct(
        public string $windowKey,
        public string $sourceKey,
        public string $title,
        public ?string $summary,
        public array $news,
        public array $claims,
        public array $factSheet,
        public string $language,
        public int $targetDuration,
    ) {}

    /**
     * @return list<int>
     */
    public function newsIds(): array
    {
        return array_values(array_map(
            fn (array $item): int => (int) $item['id'],
            $this->news,
        ));
    }

    public function primaryNewsId(): int
    {
        return (int) ($this->news[0]['id'] ?? 0);
    }

    public function sourceCount(): int
    {
        return count($this->news);
    }

    /**
     * @param  list<int>  $ids
     */
    public function evidenceFor(array $ids): string
    {
        $wanted = array_map('intval', $ids);

        $parts = array_values(array_filter(array_map(
            function (array $item) use ($wanted): ?string {
                if (! in_array((int) $item['id'], $wanted, true)) {
                    return null;
                }

                return trim(implode(' ', array_filter([
                    (string) $item['title'],
                    $item['summary'] ?? '',
                    $item['content'] ?? '',
                ])));
            },
            $this->news,
        )));

        return implode("\n", $parts);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_key' => $this->sourceKey,
            'active_window' => $this->windowKey,
            'title' => $this->title,
            'source_count' => $this->sourceCount(),
            'source_news_ids' => $this->newsIds(),
            'language' => $this->language,
            'target_duration' => $this->targetDuration,
            'claim_count' => count($this->claims),
        ];
    }
}
