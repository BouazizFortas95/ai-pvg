<?php

namespace App\Services;

use App\Models\NewsSource;

class ImportResult
{
    public ?int $sourceId = null;

    public int $totalItems = 0;

    public int $importedItems = 0;

    public int $duplicateItems = 0;

    public int $skippedItems = 0;

    public int $failedItems = 0;

    public array $errors = [];

    public ?\DateTime $startedAt = null;

    public ?\DateTime $completedAt = null;

    public function __construct(?NewsSource $source = null)
    {
        if ($source !== null) {
            $this->sourceId = $source->getKey();
        }
        $this->startedAt = new \DateTime('now', new \DateTimeZone('UTC'));
    }

    public function complete(): void
    {
        $this->completedAt = new \DateTime('now', new \DateTimeZone('UTC'));
    }
}
