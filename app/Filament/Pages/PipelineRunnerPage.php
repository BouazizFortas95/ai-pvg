<?php

namespace App\Filament\Pages;

use App\Enums\MotionLicenseStatus;
use App\Enums\UserRole;
use App\Models\CaptionTrack;
use App\Models\MotionComponent;
use App\Models\News;
use App\Models\NewsCategory;
use App\Models\NewsScore;
use App\Models\NewsSource;
use App\Models\PipelineRun;
use App\Models\PipelineRunStage;
use App\Models\ProductionManifest;
use App\Models\SceneAsset;
use App\Models\ScriptFactCheck;
use App\Models\ScriptVariant;
use App\Models\StoryCluster;
use App\Models\User;
use App\Models\VideoScript;
use App\Models\VoiceGeneration;
use App\Services\Motion\MotionLibrary;
use App\Services\Pipeline\PipelineStepService;
use App\Services\Production\ProductionManifestBuilder;
use App\Services\Production\ProductionPreflight;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;
use UnitEnum;

/**
 * Pipeline Runner Page — Principal Interactive Pipeline Studio for AI-PVG.
 *
 * Controls the 6-stage video production workflow in strictly local mode.
 * Publishing is guaranteed OFF at all times.
 */
class PipelineRunnerPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-play-circle';

    protected static ?string $navigationLabel = 'Pipeline Runner';

    protected static string|UnitEnum|null $navigationGroup = 'Pipeline Studio';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.pipeline-runner-page';

    // ── Sequential State Machine ─────────────────────────────────────────────

    /** @var int The currently active step (1 to 6). */
    public int $currentStep = 1;

    /** @var list<int> Completed step indices (e.g. [1, 2]). */
    public array $completedSteps = [];

    /** @var bool Whether all steps are unlocked for free non-linear access. */
    public bool $unlockAllSteps = false;

    /** @var int|string|null The selected or latest Story Cluster ID. */
    public int|string|null $selectedClusterId = null;

    /** @var int|string|null The selected or latest VideoScript ID. */
    public int|string|null $selectedScriptId = null;

    /** @var string Full log output from the most recent executed step. */
    public string $lastOutput = '';

    /** @var array<string, string> Historical step logs. */
    public array $stepLogs = [];

    // ── Persistent Run & Background Job State ────────────────────────────────
    public ?string $currentRunId = null;

    public ?int $runningStep = null;

    public ?int $runningStageId = null;

    public int $runningElapsed = 0;

    public ?string $runningStatus = null;

    public ?string $runningError = null;

    public ?string $runningFixHint = null;

    public bool $workerNotRunning = false;

    public ?string $appliedFlagsSummary = null;

    public function mount(): void
    {
        $this->restoreActiveRun();
    }

    // ── Step Interactive Control Properties ──────────────────────────────────

    /** @var string Step 1 News source mode: 'mock_cluster', 'ready_news', or 'live_rss'. */
    public string $step1SourceMode = 'mock_cluster';

    /** @var bool Step 1 checkbox to auto-enable local feature flags. */
    public bool $step1AutoEnableFlags = true;

    /** @var float|string|null Step 2 clustering similarity threshold. */
    public float|string|null $step2SimilarityThreshold = 0.35;

    /** @var string Step 3 Script generation mode: 'deterministic' or 'ai'. */
    public string $step3Mode = 'deterministic';

    /** @var int|string|null Step 3 Target duration in seconds. */
    public int|string|null $step3Duration = 45;

    /** @var string Step 4 Caption preset style: 'karaoke', 'highlight', 'lower_third'. */
    public string $step4CaptionStyle = 'karaoke';

    /** @var bool Step 4 Audio ducking enabled. */
    public bool $step4Ducking = true;

    /** @var array<int, string> Step 5 Multi-format aspect ratios to stage. */
    public array $step5Formats = ['9:16', '16:9', '1:1'];

    /** @var bool Step 5 Generate A/B thumbnails and metadata. */
    public bool $step5Thumbnails = true;

    /** @var string Step 5 Motion variation level: 'low' | 'medium' | 'high'. */
    public string $step5VariationLevel = 'medium';

    /** @var int Step 5 Motion PRNG Seed. */
    public int $step5MotionSeed = 1001;

    /** @var array<string, string> Step 5 Locked motion components [category => component_key]. */
    public array $step5MotionLocks = [];

    /** @var string Step 6 Render backend: 'hyperframes' (default) or 'remotion'. */
    public string $step6Backend = 'hyperframes';

    /** @var string Step 6 Target aspect ratio: '9:16', '16:9', '1:1'. */
    public string $step6AspectRatio = '9:16';

    /** @var string Step 6 Theme preset. */
    public string $step6Theme = 'cyber-pulse';

    // ── Access Control ────────────────────────────────────────────────────────

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->role === UserRole::Admin;
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            if (! Schema::hasTable('news')) {
                return null;
            }

            $count = News::query()->where('status', 'ready')->count();

            return $count > 0 ? (string) $count : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'success';
    }

    // ── Progress & State Helpers ──────────────────────────────────────────────

    public function progressPercent(): int
    {
        return (int) round((count($this->completedSteps) / 6) * 100);
    }

    public function isStepCompleted(int $step): bool
    {
        return in_array($step, $this->completedSteps, true);
    }

    public function isStepActive(int $step): bool
    {
        return $this->currentStep === $step;
    }

    public function isStepLocked(int $step): bool
    {
        if ($this->unlockAllSteps) {
            return false;
        }

        return $step > $this->currentStep && ! $this->isStepCompleted($step);
    }

    public function toggleUnlockAllSteps(): void
    {
        $this->unlockAllSteps = ! $this->unlockAllSteps;

        Notification::make()
            ->title($this->unlockAllSteps ? 'All Steps Unlocked' : 'Strict Sequential Mode Enforced')
            ->body($this->unlockAllSteps ? 'You can now trigger any step in any order.' : 'Steps unlock sequentially.')
            ->info()
            ->send();
    }

    // ── 6-Card KPI Strip Stats ───────────────────────────────────────────────

    /** @return array{ready_news: int, scores: int, clusters: int, scripts: int, captions: int, mp4s: int} */
    public function stats(): array
    {
        try {
            $mp4Count = 0;
            $outDir = storage_path('app/video_out');
            if (is_dir($outDir)) {
                $mp4Count = count(glob($outDir.'/*.mp4') ?: []);
            }

            return [
                'ready_news' => Schema::hasTable('news') ? News::query()->where('status', 'ready')->count() : 0,
                'scores' => Schema::hasTable('news_scores') ? NewsScore::query()->count() : 0,
                'clusters' => Schema::hasTable('story_clusters') ? StoryCluster::query()->count() : 0,
                'scripts' => Schema::hasTable('video_scripts') ? VideoScript::query()->count() : 0,
                'captions' => Schema::hasTable('caption_tracks') ? CaptionTrack::query()->count() : 0,
                'mp4s' => $mp4Count,
            ];
        } catch (Throwable) {
            return [
                'ready_news' => 0,
                'scores' => 0,
                'clusters' => 0,
                'scripts' => 0,
                'captions' => 0,
                'mp4s' => 0,
            ];
        }
    }

    // ── Input Synchronization & Validation Hooks ─────────────────────────────

    public function updatedSelectedClusterId(mixed $value): void
    {
        $this->selectedClusterId = is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    public function updatedSelectedScriptId(mixed $value): void
    {
        $this->selectedScriptId = is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    public function updatedStep2SimilarityThreshold(mixed $value): void
    {
        if ($value === '' || $value === null) {
            $this->step2SimilarityThreshold = null;

            return;
        }

        $this->step2SimilarityThreshold = max(0.1, min(0.95, (float) $value));
    }

    public function updatedStep3Duration(mixed $value): void
    {
        if ($value === '' || $value === null) {
            $this->step3Duration = null;

            return;
        }

        $this->step3Duration = max(10, min(180, (int) $value));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // THE 6 SEQUENTIAL STEP ACTIONS (DELEGATING TO PipelineStepService)
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Restore any active or running pipeline run from the database.
     */
    public function restoreActiveRun(): void
    {
        if (! Schema::hasTable('pipeline_runs')) {
            return;
        }

        $active = PipelineRun::query()
            ->with('stages')
            ->whereIn('status', ['running', 'pending'])
            ->latest()
            ->first();

        if ($active) {
            $this->currentRunId = $active->run_id;
            $this->completedSteps = $active->completed_steps ?? [];
            $this->currentStep = $active->current_step ?? 1;

            $runningStage = $active->stages->first(fn ($s) => in_array($s->status, ['running', 'queued'], true));
            if ($runningStage) {
                $this->runningStep = $runningStage->step_number;
                $this->runningStageId = $runningStage->id;
                $this->runningStatus = $runningStage->status;
                $this->runningElapsed = $runningStage->elapsedSeconds();
                if ($runningStage->isQueued() && $runningStage->queuedSeconds() > 15) {
                    $this->workerNotRunning = true;
                }
            }
        }
    }

    /**
     * Poll running stages for background job updates.
     */
    public function pollRunningStages(): void
    {
        if (! $this->runningStageId || ! Schema::hasTable('pipeline_run_stages')) {
            return;
        }

        $stage = PipelineRunStage::query()->find($this->runningStageId);
        if (! $stage) {
            $this->clearRunningState();

            return;
        }

        $this->runningElapsed = $stage->elapsedSeconds();
        $this->runningStatus = $stage->status;

        if ($stage->status === 'queued') {
            if ($stage->queuedSeconds() > 15) {
                $this->workerNotRunning = true;
            }
        } elseif ($stage->status === 'running') {
            $this->workerNotRunning = false;
            if ($stage->isStalled(60)) {
                $this->runningStatus = 'stalled';
            }
        }

        if ($stage->status === 'completed') {
            $step = $stage->step_number;
            $this->lastOutput = $stage->log ?? '';
            $this->stepLogs['step'.$step] = $stage->log ?? '';

            if (! in_array($step, $this->completedSteps, true)) {
                $this->completedSteps[] = $step;
            }
            $this->currentStep = min(6, $step + 1);

            Notification::make()
                ->title("Step {$step} Complete!")
                ->body("Stage completed in {$this->runningElapsed}s.")
                ->success()
                ->send();

            $this->clearRunningState();
        } elseif ($stage->status === 'failed') {
            $this->runningError = $stage->error_message;
            $this->runningFixHint = $stage->fix_hint;
            $this->lastOutput = $stage->log ?? $stage->error_message ?? '';

            Notification::make()
                ->title("Step {$stage->step_number} Failed")
                ->body($stage->error_message ?? 'Unknown error occurred.')
                ->danger()
                ->send();
        } elseif ($stage->status === 'cancelled') {
            $this->clearRunningState();
            Notification::make()->title('Step cancelled')->info()->send();
        }
    }

    /**
     * Cancel an active running background step.
     */
    public function cancelRunningStep(): void
    {
        $this->assertAdmin();

        if ($this->runningStep && $this->currentRunId) {
            $run = PipelineRun::query()->where('run_id', $this->currentRunId)->first();
            if ($run) {
                app(PipelineStepService::class)->cancelStep($this->runningStep, $run);
            }
        }

        $this->clearRunningState();

        Notification::make()
            ->title('Step Cancelled')
            ->body('Running stage was stopped.')
            ->warning()
            ->send();
    }

    /**
     * Process an active/queued step synchronously in foreground when worker is offline.
     */
    public function processRunningStepNow(): void
    {
        $this->assertAdmin();

        if (! $this->runningStep || ! $this->currentRunId) {
            return;
        }

        /** @var PipelineStepService $service */
        $service = app(PipelineStepService::class);
        $run = PipelineRun::query()->where('run_id', $this->currentRunId)->first();
        if (! $run) {
            return;
        }

        $step = $this->runningStep;
        $options = match ($step) {
            4 => [
                'script_id' => $this->selectedScriptId,
                'caption_style' => $this->step4CaptionStyle,
                'ducking' => $this->step4Ducking,
                'backend' => $this->step6Backend,
            ],
            6 => [
                'backend' => $this->step6Backend,
                'aspect_ratio' => $this->step6AspectRatio,
                'theme' => $this->step6Theme,
                'script_id' => $this->selectedScriptId,
            ],
            default => [],
        };

        try {
            $stage = $run->markStepRunning($step, PipelineStepService::STEP_NAMES[$step] ?? 'step_'.$step);
            $this->runningStatus = 'running';

            $result = $service->executeStep($step, $run, $options, auth()->id(), $stage);

            $this->recordStepComplete($step, $result['log'], min(6, $step + 1));
            $this->clearRunningState();

            Notification::make()
                ->title("Step {$step} Complete (Synchronous)")
                ->body('Stage finished successfully in foreground.')
                ->success()
                ->send();
        } catch (Throwable $e) {
            $this->runningError = $e->getMessage();
            $this->runningFixHint = 'Check the terminal output log below for details.';
            Notification::make()->title("Step {$step} Failed")->body($e->getMessage())->danger()->send();
        }
    }

    private function clearRunningState(): void
    {
        $this->runningStep = null;
        $this->runningStageId = null;
        $this->runningStatus = null;
        $this->runningElapsed = 0;
        $this->runningError = null;
        $this->runningFixHint = null;
        $this->workerNotRunning = false;
    }

    private function resolveCurrentRun(PipelineStepService $service): PipelineRun
    {
        $run = $service->findOrCreateRun([], auth()->id(), $this->currentRunId);
        $this->currentRunId = $run->run_id;

        return $run;
    }

    /**
     * Step 1: Prepare & Load Breaking News
     */
    public function runStep1(): void
    {
        $this->assertAdmin();

        try {
            /** @var PipelineStepService $service */
            $service = app(PipelineStepService::class);
            $run = $this->resolveCurrentRun($service);

            $result = $service->runStep1([
                'source_mode' => $this->step1SourceMode,
                'auto_enable_flags' => $this->step1AutoEnableFlags,
                'cluster_size' => 3,
                'backend' => $this->step6Backend,
            ], $run, auth()->id());

            $readyCount = $result['artifacts']['ready_news_count'] ?? 0;
            $flags = $result['artifacts']['flags'] ?? [];
            if ($flags !== []) {
                $this->appliedFlagsSummary = implode(', ', array_map(fn ($k, $v) => "{$k}=".($v ? 'true' : 'false'), array_keys($flags), array_values($flags)));
            }

            $this->recordStepComplete(1, $result['log'], 2);

            Notification::make()
                ->title('Step 1 Complete: News Prepared & Loaded')
                ->body("{$readyCount} ready articles available across sources. Local pipeline flags active.")
                ->success()
                ->send();
        } catch (Throwable $e) {
            $this->lastOutput = '✗ Error in Step 1: '.$e->getMessage();
            Notification::make()->title('Step 1 Failed')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * Step 2: Score News & Build Story Cluster
     */
    public function runStep2(): void
    {
        $this->assertAdmin();

        try {
            /** @var PipelineStepService $service */
            $service = app(PipelineStepService::class);
            $run = $this->resolveCurrentRun($service);

            $threshold = max(0.1, min(0.95, (float) ($this->step2SimilarityThreshold ?: 0.35)));
            $this->step2SimilarityThreshold = $threshold;

            $result = $service->runStep2([
                'similarity_threshold' => $threshold,
                'cluster_id' => $this->selectedClusterId,
                'backend' => $this->step6Backend,
            ], $run, auth()->id());

            if (! empty($result['artifacts']['cluster_id'])) {
                $this->selectedClusterId = (int) $result['artifacts']['cluster_id'];
            }

            $this->recordStepComplete(2, $result['log'], 3);

            Notification::make()
                ->title('Step 2 Complete: Story Cluster Formed')
                ->body(! empty($result['artifacts']['canonical_title'])
                    ? "Cluster #{$result['artifacts']['cluster_id']}: ".Str::limit($result['artifacts']['canonical_title'], 55)
                    : "Scored {$result['artifacts']['scored_count']} articles.")
                ->success()
                ->send();
        } catch (Throwable $e) {
            $this->lastOutput = '✗ Error in Step 2: '.$e->getMessage();
            Notification::make()->title('Step 2 Failed')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * Step 3: Generate AI Script, 3 Hooks & Fact-Check
     */
    public function runStep3(): void
    {
        $this->assertAdmin();

        try {
            /** @var PipelineStepService $service */
            $service = app(PipelineStepService::class);
            $run = $this->resolveCurrentRun($service);

            $duration = max(10, min(180, (int) ($this->step3Duration ?: 45)));
            $this->step3Duration = $duration;

            $result = $service->runStep3([
                'target_duration' => $duration,
                'cluster_id' => $this->selectedClusterId,
                'mode' => $this->step3Mode,
                'backend' => $this->step6Backend,
            ], $run, auth()->id());

            if (! empty($result['artifacts']['script_id'])) {
                $this->selectedScriptId = (int) $result['artifacts']['script_id'];
            }

            $this->recordStepComplete(3, $result['log'], 4);

            Notification::make()
                ->title('Step 3 Complete: AI Script & Hooks Generated')
                ->body("Script #{$result['artifacts']['script_id']} ({$duration}s) with {$result['artifacts']['variants_count']} hooks.")
                ->success()
                ->send();
        } catch (Throwable $e) {
            $this->lastOutput = '✗ Error in Step 3: '.$e->getMessage();
            Notification::make()->title('Step 3 Failed')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * Step 4: Generate Voice, Word Captions & Audio Mix
     */
    public function runStep4(): void
    {
        $this->assertAdmin();

        if ($this->runningStep !== null) {
            Notification::make()
                ->title('Step Already Active')
                ->body("Step {$this->runningStep} is currently active. Please wait or cancel it before dispatching another step.")
                ->warning()
                ->send();

            return;
        }

        try {
            /** @var PipelineStepService $service */
            $service = app(PipelineStepService::class);
            $run = $this->resolveCurrentRun($service);

            $options = [
                'script_id' => $this->selectedScriptId,
                'caption_style' => $this->step4CaptionStyle,
                'ducking' => $this->step4Ducking,
                'backend' => $this->step6Backend,
            ];

            if (config('queue.default') === 'sync') {
                $result = $service->runStep4($options, $run, auth()->id());
                $this->recordStepComplete(4, $result['log'], 5);
                Notification::make()
                    ->title('Step 4 Complete: Voice & Word Captions Ready')
                    ->body("{$result['artifacts']['word_timings_count']} word timings aligned with synthesized narration.")
                    ->success()
                    ->send();
            } else {
                $stage = $service->dispatchStep(4, $options, $run, auth()->id());
                $this->runningStep = 4;
                $this->runningStageId = $stage->id;
                $this->runningStatus = $stage->status;
                $this->runningElapsed = $stage->elapsedSeconds();
                Notification::make()
                    ->title('Step 4 Dispatched (Queued)')
                    ->body('Voice synthesis and caption alignment queued for execution.')
                    ->info()
                    ->send();
            }
        } catch (Throwable $e) {
            $this->lastOutput = '✗ Error in Step 4: '.$e->getMessage();
            Notification::make()->title('Step 4 Failed')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * Step 5: Attach Visual B-Roll & Multi-Format Plans
     */
    public function runStep5(): void
    {
        $this->assertAdmin();

        try {
            /** @var PipelineStepService $service */
            $service = app(PipelineStepService::class);
            $run = $this->resolveCurrentRun($service);

            $options = [
                'script_id' => $this->selectedScriptId,
                'formats' => $this->step5Formats,
                'thumbnails' => $this->step5Thumbnails,
                'backend' => $this->step6Backend,
                'motion_seed' => $this->step5MotionSeed,
                'variation_level' => $this->step5VariationLevel,
                'motion_locks' => $this->step5MotionLocks,
                'aspect_ratio' => $this->step6AspectRatio,
            ];

            $result = $service->runStep5($options, $run, auth()->id());
            $this->recordStepComplete(5, $result['log'], 6);

            Notification::make()
                ->title('Step 5 Complete: Visuals & Multi-Formats Planned')
                ->body("{$result['artifacts']['scenes_count']} scene props + ".implode(', ', $this->step5Formats).' format plans ready.')
                ->success()
                ->send();
        } catch (Throwable $e) {
            $this->lastOutput = '✗ Error in Step 5: '.$e->getMessage();
            Notification::make()->title('Step 5 Failed')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * Step 6: Render & Verify Final MP4 Video
     */
    public function runStep6(): void
    {
        $this->assertAdmin();

        if ($this->runningStep !== null) {
            Notification::make()
                ->title('Step Already Active')
                ->body("Step {$this->runningStep} is currently active. Please wait or cancel it before dispatching another step.")
                ->warning()
                ->send();

            return;
        }

        try {
            /** @var PipelineStepService $service */
            $service = app(PipelineStepService::class);
            $run = $this->resolveCurrentRun($service);

            $script = $this->resolveSelectedScript();

            // Preflight check gate before render execution when a script is present
            if ($script !== null) {
                /** @var ProductionPreflight $preflight */
                $preflight = app(ProductionPreflight::class);
                $check = $preflight->check([
                    'script_id' => $script->id,
                    'cluster_id' => $this->selectedClusterId,
                    'run' => $run,
                    'aspect_ratio' => $this->step6AspectRatio,
                    'backend' => $this->step6Backend,
                ]);

                if (! $check['ready_to_render']) {
                    $this->lastOutput = "=== Step 6 Preflight: BLOCKED ===\n".ProductionPreflight::formatMissing($check);
                    Notification::make()
                        ->title('Step 6 Blocked: Preflight Failed')
                        ->body('Missing required data from earlier steps. See output log for details.')
                        ->danger()
                        ->send();

                    return;
                }
            }

            $options = [
                'backend' => $this->step6Backend,
                'aspect_ratio' => $this->step6AspectRatio,
                'theme' => $this->step6Theme,
                'script_id' => $this->selectedScriptId,
                'cluster_id' => $this->selectedClusterId,
            ];

            if (config('queue.default') === 'sync') {
                $result = $service->runStep6($options, $run, auth()->id());
                $this->recordStepComplete(6, $result['log'], 6);
                Notification::make()
                    ->title('Step 6 Complete: Video Rendered Locally! 🎉')
                    ->body('Playable .mp4 verified and saved in storage/app/video_out/')
                    ->success()
                    ->send();
            } else {
                $stage = $service->dispatchStep(6, $options, $run, auth()->id());
                $this->runningStep = 6;
                $this->runningStageId = $stage->id;
                $this->runningStatus = $stage->status;
                $this->runningElapsed = $stage->elapsedSeconds();
                Notification::make()
                    ->title('Step 6 Dispatched (Queued)')
                    ->body("Video render queued for execution via {$this->step6Backend}.")
                    ->info()
                    ->send();
            }
        } catch (Throwable $e) {
            $this->lastOutput = '✗ Error in Step 6: '.$e->getMessage();
            Notification::make()->title('Step 6 Failed')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * Run Preflight Diagnostic Check
     */
    public function runPreflight(): void
    {
        $this->assertAdmin();

        try {
            /** @var ProductionPreflight $preflight */
            $preflight = app(ProductionPreflight::class);
            $run = $this->currentRunId && Schema::hasTable('pipeline_runs')
                ? PipelineRun::query()->where('run_id', $this->currentRunId)->first()
                : null;

            $check = $preflight->check([
                'script_id' => $this->selectedScriptId,
                'cluster_id' => $this->selectedClusterId,
                'run' => $run,
                'aspect_ratio' => $this->step6AspectRatio,
                'backend' => $this->step6Backend,
            ]);

            $log = "=== Production Preflight Inspection ===\n";
            foreach ($check['passed_checks'] as $p) {
                $log .= "✓ [Step {$p['step']}] {$p['label']}: {$p['details']}\n";
            }
            if (! empty($check['missing_data'])) {
                $log .= "\n⚠️ Missing Requirements:\n";
                foreach ($check['missing_data'] as $m) {
                    $log .= "✗ [Step {$m['step']}: {$m['step_name']}] {$m['label']}: {$m['details']}\n";
                }
            } else {
                $log .= "\n✓ All stages passed data map verification. READY TO RENDER.\n";
            }

            try {
                Artisan::call('ai-pvg:verify-mp4');
                $log .= "\n✓ Verification: ".trim(Artisan::output());
            } catch (Throwable) {
                // Verify command optional
            }

            $this->lastOutput = $log;

            if ($check['ready_to_render']) {
                Notification::make()->title('Preflight: Ready to Render')->body('All required data and safety locks verified.')->success()->send();
            } else {
                Notification::make()->title('Preflight: Missing Requirements')->body(count($check['missing_data']).' requirement(s) need completion before rendering.')->warning()->send();
            }
        } catch (Throwable $e) {
            $this->lastOutput = '✗ Preflight Failed: '.$e->getMessage();
            Notification::make()->title('Preflight Failed')->body($e->getMessage())->danger()->send();
        }
    }

    public function reRollMotion(): void
    {
        $this->step5MotionSeed = random_int(1000, 99999);
        Notification::make()
            ->title('Motion Re-Rolled')
            ->body("New PRNG Seed: {$this->step5MotionSeed}")
            ->info()
            ->send();
    }

    public function toggleMotionLock(string $category, string $componentKey): void
    {
        if (isset($this->step5MotionLocks[$category]) && $this->step5MotionLocks[$category] === $componentKey) {
            unset($this->step5MotionLocks[$category]);
            Notification::make()->title("Unlocked {$category}")->info()->send();
        } else {
            $this->step5MotionLocks[$category] = $componentKey;
            Notification::make()->title("Locked {$category} to {$componentKey}")->success()->send();
        }
    }

    /** @return array<string, mixed> */
    public function step5MotionData(): array
    {
        try {
            if (! Schema::hasTable('motion_components')) {
                return ['available' => [], 'counts' => ['original' => 0, 'cleared' => 0, 'reference_only' => 0]];
            }

            $components = MotionComponent::query()->get();
            $counts = [
                'original' => $components->where('license_status', MotionLicenseStatus::Original)->count(),
                'cleared' => $components->where('license_status', MotionLicenseStatus::Cleared)->count(),
                'reference_only' => $components->where('license_status', MotionLicenseStatus::ReferenceOnly)->count(),
            ];

            return [
                'available' => $components->where('status', \App\Enums\MotionComponentStatus::Approved)->values()->all(),
                'counts' => $counts,
            ];
        } catch (Throwable) {
            return ['available' => [], 'counts' => ['original' => 0, 'cleared' => 0, 'reference_only' => 0]];
        }
    }

    /** @return array<string, mixed>|null */
    public function step6ManifestData(): ?array
    {
        try {
            if (! $this->currentRunId || ! Schema::hasTable('production_manifests')) {
                return null;
            }

            $manifest = ProductionManifest::query()->where('run_id', $this->currentRunId)->first();

            return $manifest?->manifest_data;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Reset the wizard back to Step 1.
     */
    public function resetWizard(): void
    {
        $this->assertAdmin();

        $this->currentStep = 1;
        $this->completedSteps = [];
        $this->selectedClusterId = null;
        $this->selectedScriptId = null;
        $this->clearRunningState();

        if ($this->currentRunId && Schema::hasTable('pipeline_runs')) {
            PipelineRun::query()->where('run_id', $this->currentRunId)->update(['status' => 'cancelled']);
        }
        $this->currentRunId = null;
        $this->lastOutput = "Wizard reset to Step 1.\nClick 'Run Step 1: Prepare & Load News' to begin.";

        Notification::make()
            ->title('Wizard Reset')
            ->body('Pipeline studio reset to Step 1. Production database rows remain untouched.')
            ->info()
            ->send();
    }

    // ── Metric & Data Providers for View ──────────────────────────────────────

    /** @return array<int, string> */
    public function clusterOptions(): array
    {
        try {
            if (! Schema::hasTable('story_clusters')) {
                return [];
            }

            return StoryCluster::query()
                ->latest()
                ->limit(15)
                ->get()
                ->mapWithKeys(fn (StoryCluster $c) => [
                    $c->getKey() => '#'.$c->getKey().' — '.Str::limit($c->canonical_title ?? 'Untitled', 45).' (conf: '.number_format((float) ($c->confidence ?? 0), 2).')',
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<int, string> */
    public function scriptOptions(): array
    {
        try {
            if (! Schema::hasTable('video_scripts')) {
                return [];
            }

            return VideoScript::query()
                ->latest()
                ->limit(15)
                ->get()
                ->mapWithKeys(fn (VideoScript $s) => [
                    $s->getKey() => '#'.$s->getKey().' — '.Str::limit($s->title ?? 'Untitled', 45).' ('.($s->target_duration ?? 45).'s)',
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, int|string> */
    public function step1Metrics(): array
    {
        try {
            return [
                'active_sources' => Schema::hasTable('news_sources')
                    ? NewsSource::query()->where('is_active', true)->count() : 0,
                'ready_news' => Schema::hasTable('news')
                    ? News::query()->where('status', 'ready')->count() : 0,
                'categories' => Schema::hasTable('news_categories')
                    ? NewsCategory::query()->count() : 0,
            ];
        } catch (Throwable) {
            return ['active_sources' => '?', 'ready_news' => '?', 'categories' => '?'];
        }
    }

    /** @return array<string, int|string> */
    public function step2Metrics(): array
    {
        try {
            $latestCluster = null;
            if ($this->selectedClusterId && Schema::hasTable('story_clusters')) {
                $latestCluster = StoryCluster::query()->find($this->selectedClusterId);
            }
            if (! $latestCluster && Schema::hasTable('story_clusters')) {
                $latestCluster = StoryCluster::query()->latest()->first();
            }

            return [
                'scored' => Schema::hasTable('news_scores') ? NewsScore::query()->count() : 0,
                'clusters' => Schema::hasTable('story_clusters') ? StoryCluster::query()->count() : 0,
                'latest_cluster' => $latestCluster?->canonical_title ?? '—',
                'latest_score' => $latestCluster
                    ? number_format((float) ($latestCluster->confidence ?? 0), 2)
                    : '—',
            ];
        } catch (Throwable) {
            return ['scored' => '?', 'clusters' => '?', 'latest_cluster' => '?', 'latest_score' => '?'];
        }
    }

    /** @return array<string, int|string> */
    public function step3Metrics(): array
    {
        try {
            $script = $this->resolveSelectedScript();
            $variants = 0;
            $supported = 0;

            if ($script && Schema::hasTable('script_variants')) {
                $variants = ScriptVariant::query()->where('video_script_id', $script->getKey())->count();
            }
            if ($script && Schema::hasTable('script_fact_checks')) {
                $supported = ScriptFactCheck::query()->where('video_script_id', $script->getKey())->where('verdict', 'supported')->count();
            }

            return [
                'title' => $script?->title ?? '—',
                'duration' => $script?->target_duration ? ($script->target_duration.'s') : ($this->step3Duration ? $this->step3Duration.'s' : '45s'),
                'variants' => $variants > 0 ? $variants : (Schema::hasTable('script_variants') ? ScriptVariant::query()->count() : 0),
                'scenes_count' => count($script?->scenes ?? []),
                'supported' => $supported,
            ];
        } catch (Throwable) {
            return ['title' => '?', 'duration' => $this->step3Duration ? $this->step3Duration.'s' : '45s', 'variants' => '?', 'scenes_count' => 0, 'supported' => 0];
        }
    }

    /** @return array<string, int|string> */
    public function step4Metrics(): array
    {
        try {
            $script = $this->resolveSelectedScript();
            $track = null;

            if ($script && Schema::hasTable('caption_tracks')) {
                $track = CaptionTrack::query()->where('video_script_id', $script->getKey())->latest()->first();
            }

            $timingsCount = is_array($track?->word_timings) ? count($track->word_timings) : 0;

            return [
                'voice_gens' => Schema::hasTable('voice_generations') ? VoiceGeneration::query()->count() : 0,
                'word_timings' => $timingsCount,
                'caption_status' => $track?->status ?? 'none',
            ];
        } catch (Throwable) {
            return ['voice_gens' => '?', 'word_timings' => '?', 'caption_status' => '?'];
        }
    }

    /** @return array<string, int|string> */
    public function step5Metrics(): array
    {
        try {
            $script = $this->resolveSelectedScript();
            $scenesCount = count($script?->scenes ?? []);
            $assetsMapped = 0;

            if ($script && Schema::hasTable('scene_assets')) {
                $assetsMapped = SceneAsset::query()->where('video_script_id', $script->getKey())->count();
            }

            return [
                'scenes_count' => $scenesCount,
                'assets_mapped' => $assetsMapped,
                'formats' => implode(', ', $this->step5Formats),
            ];
        } catch (Throwable) {
            return ['scenes_count' => '?', 'assets_mapped' => '?', 'formats' => '?'];
        }
    }

    /** @return array{mp4_files: list<array<string, string>>, mp4_count: int} */
    public function step6Metrics(): array
    {
        try {
            $outDir = storage_path('app/video_out');
            $files = [];

            if (is_dir($outDir)) {
                foreach (File::glob($outDir.'/*.mp4') as $path) {
                    $size = filesize($path);
                    $files[] = [
                        'name' => basename($path),
                        'size' => $size !== false ? $this->humanBytes($size) : '?',
                        'path' => $path,
                    ];
                }
            }

            return [
                'mp4_files' => $files,
                'mp4_count' => count($files),
            ];
        } catch (Throwable) {
            return ['mp4_files' => [], 'mp4_count' => 0];
        }
    }

    // ── Backward-Compatibility Action Aliases ─────────────────────────────────

    public function enableAllLocalFlags(): void
    {
        $this->assertAdmin();
        $this->applyLocalPipelineSafety();
        Notification::make()->title('All local pipeline flags enabled')->body('Publishing remains locked OFF.')->success()->send();
    }

    public function runFetchRss(): void
    {
        $this->step1SourceMode = 'live_rss';
        $this->runStep1();
    }

    public function runSeedMockCluster(): void
    {
        $this->step1SourceMode = 'mock_cluster';
        $this->runStep1();
    }

    public function runTriageAndCluster(): void
    {
        $this->runStep2();
    }

    public function runScriptPipeline(): void
    {
        $this->runStep3();
    }

    public function runVoiceAndCaptions(): void
    {
        $this->runStep4();
    }

    public function runHarvestAssets(): void
    {
        $this->runStep5();
    }

    public function runPreflightCheck(): void
    {
        $this->runPreflight();
    }

    public function runRenderMp4(): void
    {
        $this->runStep6();
    }

    public function runMultiFormat(): void
    {
        $this->runStep5();
    }

    public function runCoPilotApprove(): void
    {
        $this->assertAdmin();
        Notification::make()->title('Co-Pilot Approved (Local)')->success()->send();
    }

    public function runDiagnostics(): void
    {
        $this->assertAdmin();
        try {
            Artisan::call('ai-pvg:diagnostics');
            $this->lastOutput = Artisan::output();
            Notification::make()->title('Diagnostics complete')->success()->send();
        } catch (Throwable $e) {
            Notification::make()->title('Diagnostics failed')->body($e->getMessage())->danger()->send();
        }
    }

    // ── Internal Helpers ──────────────────────────────────────────────────────

    private function assertAdmin(): void
    {
        abort_unless(static::canAccess(), 403, 'Unauthorized');
    }

    private function applyLocalPipelineSafety(): array
    {
        /** @var PipelineStepService $service */
        $service = app(PipelineStepService::class);
        $flags = $service->applySafetyFlags([
            'backend' => $this->step6Backend,
            'enable_avatar' => false,
        ]);

        $this->appliedFlagsSummary = implode(', ', array_map(fn ($k, $v) => "{$k}=".($v ? 'true' : 'false'), array_keys($flags), array_values($flags)));

        return $flags;
    }

    private function recordStepComplete(int $step, string $log, int $nextStep): void
    {
        $this->lastOutput = $log;
        $this->stepLogs['step'.$step] = $log;

        if (! in_array($step, $this->completedSteps, true)) {
            $this->completedSteps[] = $step;
        }

        $this->currentStep = max($this->currentStep, $nextStep);
    }

    private function resolveScriptSubject(): StoryCluster|News|null
    {
        try {
            if ($this->selectedClusterId && Schema::hasTable('story_clusters')) {
                $cluster = StoryCluster::query()->find($this->selectedClusterId);
                if ($cluster) {
                    return $cluster;
                }
            }

            if (Schema::hasTable('story_clusters')) {
                $cluster = StoryCluster::query()->latest()->first();
                if ($cluster) {
                    return $cluster;
                }
            }

            if (Schema::hasTable('news')) {
                return News::query()->where('status', 'ready')->latest()->first();
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveSelectedScript(): ?VideoScript
    {
        try {
            if (! Schema::hasTable('video_scripts')) {
                return null;
            }

            if ($this->selectedScriptId) {
                return VideoScript::query()->find($this->selectedScriptId);
            }

            return VideoScript::query()->latest()->first();
        } catch (Throwable) {
            return null;
        }
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1_048_576) {
            return number_format($bytes / 1024, 1).' KB';
        }

        if ($bytes < 1_073_741_824) {
            return number_format($bytes / 1_048_576, 1).' MB';
        }

        return number_format($bytes / 1_073_741_824, 2).' GB';
    }
}
