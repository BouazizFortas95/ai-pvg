<?php

namespace App\Services\Automation\Triage;

use App\Models\NewsScore;
use App\Models\StoryCluster;
use App\Services\Automation\Greenlight\GreenlightResult;

final class TriageReport
{
    /**
     * @param  array<int, NewsScore>  $scores
     * @param  list<StoryCluster>  $clusters
     * @param  array<int, GreenlightResult>  $greenlights
     * @param  array<string, mixed>  $clusterDiagnostics
     */
    public function __construct(
        public readonly array $scores,
        public readonly array $clusters,
        public readonly array $greenlights,
        public readonly array $clusterDiagnostics = [],
    ) {}

    public function scoredCount(): int
    {
        return count($this->scores);
    }

    public function clusterCount(): int
    {
        return count($this->clusters);
    }

    public function greenlightCount(): int
    {
        return count($this->greenlights);
    }
}
