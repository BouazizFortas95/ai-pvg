<?php

namespace Tests\Unit;

use App\Enums\AutomationStageStatus;
use App\Services\Automation\StageResult;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class StageResultTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_success_result_carries_output_and_cost(): void
    {
        $result = StageResult::success(['score' => 91], 250);

        $this->assertSame(AutomationStageStatus::Completed, $result->status);
        $this->assertTrue($result->successful());
        $this->assertSame(['score' => 91], $result->output);
        $this->assertSame(250, $result->costMicros);
        $this->assertNull($result->errorMessage);
        $this->assertFalse($result->retryable);
    }

    public function test_failure_and_skipped_results_are_truthful(): void
    {
        $failure = StageResult::failure('Provider timeout', true, 10, ['attempted' => true]);
        $skipped = StageResult::skipped('No news in window');

        $this->assertSame(AutomationStageStatus::Failed, $failure->status);
        $this->assertTrue($failure->retryable);
        $this->assertSame('Provider timeout', $failure->errorMessage);
        $this->assertSame(10, $failure->costMicros);

        $this->assertSame(AutomationStageStatus::Skipped, $skipped->status);
        $this->assertSame('No news in window', $skipped->errorMessage);
        $this->assertSame('No news in window', $skipped->output['reason']);
        $this->assertFalse($skipped->successful());
    }

    public function test_non_terminal_status_and_negative_cost_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StageResult(AutomationStageStatus::Running);
    }

    public function test_negative_cost_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StageResult::success([], -1);
    }
}
