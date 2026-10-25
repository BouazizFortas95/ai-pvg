<?php

namespace App\Services\Automation\Triage;

use App\Enums\NewsStatus;
use App\Models\News;
use App\Services\Automation\Clustering\StoryClusteringService;
use App\Services\Automation\Greenlight\AutoGreenlightService;
use App\Services\Automation\Scoring\NewsScoringService;
use DateTimeInterface;

/**
 * Shared G-02 orchestration used by the manual command and the queued
 * post-import handshake. Scoring and clustering stay deterministic and
 * local; no provider or HTTP call is made here.
 */
final class TriageRunner
{
    public function __construct(
        private readonly NewsScoringService $scoring,
        private readonly StoryClusteringService $clustering,
        private readonly AutoGreenlightService $greenlight,
    ) {}

    public function run(
        bool $runScoring = true,
        bool $runClustering = true,
        ?DateTimeInterface $asOf = null,
    ): TriageReport {
        $scores = [];

        if ($runScoring) {
            $news = News::query()
                ->with('source')
                ->where('status', NewsStatus::Ready->value)
                ->whereDoesntHave('newsScore')
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->get();

            $scores = $this->scoring->scoreMany($news, $asOf);
        }

        $clusters = [];
        $greenlights = [];
        $clusterDiagnostics = [];

        if ($runClustering) {
            $clusters = $this->clustering->cluster($asOf);

            foreach ($clusters as $cluster) {
                $greenlights[(int) $cluster->getKey()] = $this->greenlight->evaluateCluster($cluster, $asOf);
            }

            if ($clusters === []) {
                $clusterDiagnostics = $this->clustering->diagnose($asOf);
            }
        }

        return new TriageReport($scores, $clusters, $greenlights, $clusterDiagnostics);
    }
}
