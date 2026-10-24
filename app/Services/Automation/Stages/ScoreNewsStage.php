<?php

namespace App\Services\Automation\Stages;

use App\Contracts\AutomationStageInterface;
use App\Models\News;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\Scoring\NewsScoringService;
use App\Services\Automation\StageResult;
use App\Services\Automation\Triage\TriageGate;
use Carbon\CarbonImmutable;
use Throwable;

class ScoreNewsStage implements AutomationStageInterface
{
    public function __construct(
        private readonly TriageGate $gate,
        private readonly NewsScoringService $scoring,
    ) {}

    public function run(AutomationContext $context): StageResult
    {
        if (! $this->gate->enabled()) {
            return StageResult::skipped('triage_disabled');
        }

        $news = $this->resolveNews($context);

        if ($news === null) {
            return StageResult::failure('ScoreNewsStage requires a News subject or news_id payload.');
        }

        try {
            $score = $this->scoring->score($news, $this->asOf($context));
        } catch (Throwable $exception) {
            return StageResult::failure($exception->getMessage());
        }

        return StageResult::success([
            'news_id' => (int) $news->getKey(),
            'composite_score' => (float) $score->composite_score,
            'band' => $score->band?->value,
            'breakdown' => $score->breakdown,
        ]);
    }

    public function isRetryable(): bool
    {
        return false;
    }

    public function costEstimate(AutomationContext $context): int
    {
        return 0;
    }

    private function resolveNews(AutomationContext $context): ?News
    {
        $subject = $context->run->subject;

        if ($subject instanceof News) {
            return $subject;
        }

        $newsId = $context->payload['news_id'] ?? null;

        return is_numeric($newsId) ? News::query()->find((int) $newsId) : null;
    }

    private function asOf(AutomationContext $context): ?CarbonImmutable
    {
        $asOf = $context->payload['as_of'] ?? null;

        return is_string($asOf) && trim($asOf) !== ''
            ? CarbonImmutable::parse($asOf)
            : null;
    }
}
