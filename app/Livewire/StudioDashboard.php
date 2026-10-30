<?php

namespace App\Livewire;

use App\Enums\ScriptVariantType;
use App\Enums\UserRole;
use App\Enums\VideoScriptStatus;
use App\Models\AutomationRun;
use App\Models\CoPilotReviewSession;
use App\Models\News;
use App\Models\NewsScore;
use App\Models\PublishingApprovalSession;
use App\Models\ScriptVariant;
use App\Models\StoryCluster;
use App\Models\VideoScript;
use App\Services\AI\ScriptPipeline\DirectiveValidator;
use App\Services\AI\ScriptPipeline\NarrativeArchitectAgent;
use App\Services\AI\ScriptPipeline\ScriptAgentsGate;
use App\Services\AI\ScriptPipeline\ScriptSource;
use App\Services\AI\ScriptPipeline\ScriptSourceFactory;
use App\Services\Automation\Greenlight\AutoGreenlightService;
use App\Services\Automation\Triage\TriageGate;
use App\Services\Publishing\PublishingGate;
use App\Services\ThemeEngine;
use App\Support\HyperFramesRuntime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('layouts.blank')]
class StudioDashboard extends Component
{
    public string $themeName = 'Cyber Dark';

    public string $primaryColor = '#00d4ff';

    public string $mode = 'dark';

    public string $triageMessage = '';

    public string $scriptMessage = '';

    public ?int $previewScriptId = null;

    public int $previewHookIndex = 1;

    public function mount(): void
    {
        $user = auth()->user();
        $prefs = $user ? ThemeEngine::getThemeForUser($user->id) : null;
        $this->themeName = $prefs ? 'Custom' : 'Cyber Dark';
        $this->primaryColor = $prefs ? ($prefs['--primary'] ?? '#00d4ff') : '#00d4ff';
        $this->mode = $prefs ? ($prefs['--mode'] ?? 'dark') : 'dark';
    }

    public function getStatsProperty(): array
    {
        return [
            'news_count' => News::count(),
            'script_total' => VideoScript::count(),
            'script_generated' => VideoScript::where('status', 'generated')->count(),
            'script_approved' => VideoScript::where('status', 'approved')->count(),
            'script_pending' => VideoScript::where('status', 'draft')->count(),
        ];
    }

    public function saveTheme(): void
    {
        $user = auth()->user();
        if ($user) {
            ThemeEngine::saveForUser($user->id, [
                'primary_color' => $this->primaryColor,
                'secondary_color' => '#1e293b',
                'accent_color' => '#64748b',
                'background_color' => '#0f172a',
                'text_color' => '#f8fafc',
                'mode' => $this->mode,
                'border_radius' => 8,
            ]);
            $this->dispatch('theme-saved');
        }
    }

    public function generateScript(int $clusterId): void
    {
        $user = auth()->user();

        if ($user === null || $user->role !== UserRole::Admin) {
            $this->triageMessage = 'Only administrators can request a triage script.';

            return;
        }

        if (! Schema::hasTable('story_clusters') || ! Schema::hasTable('story_cluster_news')) {
            $this->triageMessage = 'Triage tables are not available. Run the additive migrations first.';

            return;
        }

        $gate = app(TriageGate::class);

        if (! $gate->enabled()) {
            $this->triageMessage = 'Triage is disabled. Enable automation.triage.enabled or the triage feature flag first.';

            return;
        }

        $cluster = StoryCluster::query()->with(['news.newsScore'])->find($clusterId);

        if ($cluster === null) {
            $this->triageMessage = 'Story cluster not found.';

            return;
        }

        try {
            $result = app(AutoGreenlightService::class)->evaluateCluster($cluster);
            $this->triageMessage = $result->message;
        } catch (Throwable $exception) {
            $this->triageMessage = 'Triage action failed: '.$exception->getMessage();
        }
    }

    public function selectHook(int $variantId): void
    {
        if (! $this->canReviewScriptAgents()) {
            $this->scriptMessage = 'Only administrators can select a hook variant.';

            return;
        }

        if (! $this->scriptAgentTablesAvailable()) {
            $this->scriptMessage = 'Script agent tables are not available. Run the additive G-03 migrations first.';

            return;
        }

        if (! app(ScriptAgentsGate::class)->enabled()) {
            $this->scriptMessage = 'Script agents are disabled. Enable automation.script_agents.enabled and the script_agents.enabled feature flag first.';

            return;
        }

        $variant = ScriptVariant::query()->with('videoScript')->find($variantId);

        if ($variant === null || $variant->videoScript === null) {
            $this->scriptMessage = 'Hook variant not found.';

            return;
        }

        $script = $variant->videoScript;

        if ($script->status === VideoScriptStatus::Approved) {
            $this->scriptMessage = 'Approved scripts are protected; hook selection was refused.';

            return;
        }

        DB::transaction(function () use ($script, $variant): void {
            $script->variants()->update(['selected' => false]);
            $script->variants()->whereKey($variant->getKey())->update(['selected' => true]);
            $variant->forceFill(['selected' => true])->syncOriginalAttribute('selected');
        });

        $this->previewScriptId = (int) $script->getKey();
        $this->previewHookIndex = (int) $variant->hook_index;
        $this->scriptMessage = 'Hook #'.$variant->hook_index.' selected for script #'.$script->getKey().'. No publishing action was taken.';
    }

    /**
     * Read-only directive preview for an existing hook. It never persists a
     * scene change and never performs a paid provider call.
     */
    public function previewHook(int $variantId): void
    {
        if (! $this->canReviewScriptAgents()) {
            $this->scriptMessage = 'Only administrators can preview scene directives.';

            return;
        }

        if (! $this->scriptAgentTablesAvailable()) {
            $this->scriptMessage = 'Script agent tables are not available.';

            return;
        }

        $variant = ScriptVariant::query()->with('videoScript')->find($variantId);

        if ($variant === null || $variant->videoScript === null) {
            $this->scriptMessage = 'Hook variant not found.';

            return;
        }

        $this->previewScriptId = (int) $variant->videoScript->getKey();
        $this->previewHookIndex = (int) $variant->hook_index;
        $this->scriptMessage = 'Previewing scene directives for hook #'.$variant->hook_index.'. Nothing was changed.';
    }

    public function render()
    {
        $triageEnabled = app(TriageGate::class)->enabled();
        $triageScores = collect();
        $triageClusters = collect();
        $scriptPreviews = [];
        $scriptAgentsEnabled = false;
        $scriptTablesAvailable = $this->scriptAgentTablesAvailable();

        if (Schema::hasTable('news_scores') && Schema::hasTable('news')) {
            $triageScores = NewsScore::query()
                ->with('news')
                ->orderByDesc('composite_score')
                ->limit(10)
                ->get();
        }

        if (Schema::hasTable('story_clusters')
            && Schema::hasTable('story_cluster_news')
            && Schema::hasTable('news')) {
            $triageClusters = StoryCluster::query()
                ->with(['news.newsScore', 'news.source'])
                ->orderByDesc('window_end')
                ->limit(10)
                ->get();
        }

        if ($scriptTablesAvailable) {
            $scriptAgentsEnabled = app(ScriptAgentsGate::class)->enabled();
            $scriptPreviews = $this->buildScriptPreviews();
        }

        return view('studio.dashboard', [
            'stats' => $this->stats,
            'scripts' => VideoScript::with('news')->latest()->take(5)->get(),
            'news' => News::latest()->take(5)->get(),
            'triageEnabled' => $triageEnabled,
            'triageScores' => $triageScores,
            'triageClusters' => $triageClusters,
            'scriptAgentsEnabled' => $scriptAgentsEnabled,
            'scriptTablesAvailable' => $scriptTablesAvailable,
            'scriptPreviews' => $scriptPreviews,
            'phaseH' => $this->buildPhaseHStatus(),
            'isAdmin' => $this->canReviewScriptAgents(),
            'hyperframes' => HyperFramesRuntime::status(),
        ]);
    }

    private function canReviewScriptAgents(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->role === UserRole::Admin;
    }

    /**
     * Read-only Phase H oversight. No publish, upload, approval, or reject
     * action is exposed here; missing tables fail closed.
     *
     * @return array<string, mixed>
     */
    private function buildPhaseHStatus(): array
    {
        $approvalTable = Schema::hasTable('publishing_approval_sessions');
        $coPilotTable = Schema::hasTable('co_pilot_review_sessions');
        $videoScriptTable = Schema::hasTable('video_scripts');
        $userTable = Schema::hasTable('users');
        $tablesAvailable = $approvalTable && $coPilotTable && $videoScriptTable && $userTable;

        $sessions = collect();
        $reviews = collect();

        if ($approvalTable && $videoScriptTable) {
            $sessions = PublishingApprovalSession::query()
                ->with(['videoScript', 'firstApprover', 'secondApprover'])
                ->latest('id')
                ->limit(8)
                ->get();
        }

        if ($coPilotTable && $userTable) {
            $reviews = CoPilotReviewSession::query()
                ->with('user')
                ->latest('id')
                ->limit(8)
                ->get();
        }

        $approvalTotal = $approvalTable ? PublishingApprovalSession::query()->count() : 0;
        $approvalPending = $approvalTable
            ? PublishingApprovalSession::query()->where('status', PublishingApprovalSession::STATUS_PENDING)->count()
            : 0;
        $approvalApproved = $approvalTable
            ? PublishingApprovalSession::query()->where('status', PublishingApprovalSession::STATUS_APPROVED)->count()
            : 0;
        $distinctApproved = $approvalTable
            ? PublishingApprovalSession::query()
                ->where('status', PublishingApprovalSession::STATUS_APPROVED)
                ->whereNotNull('first_approver_id')
                ->whereNotNull('second_approver_id')
                ->whereColumn('first_approver_id', '!=', 'second_approver_id')
                ->count()
            : 0;

        $coPilotTotal = $coPilotTable ? CoPilotReviewSession::query()->count() : 0;
        $coPilotApproved = $coPilotTable
            ? CoPilotReviewSession::query()->where('action', CoPilotReviewSession::ACTION_APPROVE)->count()
            : 0;
        $coPilotRejected = $coPilotTable
            ? CoPilotReviewSession::query()->where('action', CoPilotReviewSession::ACTION_REJECT)->count()
            : 0;

        $publishingGate = app(PublishingGate::class);

        return [
            'tables_available' => $tablesAvailable,
            'approval_table' => $approvalTable,
            'co_pilot_table' => $coPilotTable,
            'publishing_enabled' => $publishingGate->enabled(),
            'publishing_flag' => $publishingGate->flagKey(),
            'approval_total' => $approvalTotal,
            'approval_pending' => $approvalPending,
            'approval_approved' => $approvalApproved,
            'distinct_approved' => $distinctApproved,
            'co_pilot_total' => $coPilotTotal,
            'co_pilot_approved' => $coPilotApproved,
            'co_pilot_rejected' => $coPilotRejected,
            'sessions' => $sessions,
            'reviews' => $reviews,
            'dual_required' => true,
        ];
    }

    private function scriptAgentTablesAvailable(): bool
    {
        return Schema::hasTable('script_variants')
            && Schema::hasTable('script_fact_checks')
            && Schema::hasTable('video_scripts');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildScriptPreviews(): array
    {
        $scripts = VideoScript::query()
            ->with(['variants', 'factChecks', 'news'])
            ->whereHas('variants')
            ->latest('id')
            ->limit(5)
            ->get();

        if ($scripts->isEmpty()) {
            return [];
        }

        $activeScriptId = $this->previewScriptId;

        if ($activeScriptId === null || $scripts->firstWhere('id', $activeScriptId) === null) {
            $activeScriptId = (int) $scripts->first()->getKey();
        }

        $previews = [];

        foreach ($scripts as $script) {
            $hooks = $script->variants
                ->where('variant_type', ScriptVariantType::Hook)
                ->sortBy('hook_index')
                ->values();
            $selected = $script->variants
                ->where('variant_type', ScriptVariantType::Hook)
                ->firstWhere('selected', true);
            $checks = $script->factChecks->sortBy('id')->values();
            $preview = null;

            if ((int) $script->getKey() === (int) $activeScriptId && $hooks->isNotEmpty()) {
                $preview = $this->buildDirectivePreview($script, $hooks, $this->previewHookIndex);
            }

            $previews[] = [
                'script' => $script,
                'hooks' => $hooks,
                'selected_hook_index' => (int) ($selected?->hook_index ?? 1),
                'fact_checks' => $checks,
                'preview' => $preview,
                'is_active' => (int) $script->getKey() === (int) $activeScriptId,
                'approved' => $script->status === VideoScriptStatus::Approved,
            ];
        }

        return $previews;
    }

    /**
     * Compose an in-memory preview for a hook so the Studio can show the
     * directives that would be produced and how they differ from persisted
     * data. No model is saved and no provider is called.
     *
     * @param  Collection<int, ScriptVariant>  $hooks
     * @return array<string, mixed>|null
     */
    private function buildDirectivePreview(VideoScript $script, $hooks, int $hookIndex): ?array
    {
        $variant = $hooks->firstWhere('hook_index', $hookIndex) ?? $hooks->first();

        if (! $variant instanceof ScriptVariant) {
            return null;
        }

        try {
            $source = $this->resolvePreviewSource($script);

            if ($source === null) {
                return null;
            }

            $scenes = app(NarrativeArchitectAgent::class)->compose($source, (string) $variant->content);
            app(DirectiveValidator::class)->validate($scenes, $script->target_duration, $source->newsIds());
        } catch (Throwable $exception) {
            return [
                'hook_index' => (int) $variant->hook_index,
                'available' => false,
                'error' => $exception->getMessage(),
                'scenes' => [],
                'diff' => [],
            ];
        }

        return [
            'hook_index' => (int) $variant->hook_index,
            'available' => true,
            'error' => null,
            'scenes' => $scenes,
            'diff' => $this->directiveDiff($script, $scenes),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $previewScenes
     * @return list<array{scene: int, field: string, persisted: string, preview: string}>
     */
    private function directiveDiff(VideoScript $script, array $previewScenes): array
    {
        $fields = ['camera_motion', 'b_roll_query', 'highlight_text', 'sfx_cue', 'emotion_tone', 'duration_seconds'];
        $persisted = is_array($script->scenes) ? $script->scenes : [];
        $diff = [];

        foreach ($previewScenes as $index => $scene) {
            $current = $persisted[$index]['directives'] ?? null;
            $preview = $scene['directives'] ?? null;

            if (! is_array($preview)) {
                continue;
            }

            foreach ($fields as $field) {
                $from = is_array($current) && array_key_exists($field, $current)
                    ? (string) $current[$field]
                    : '—';
                $to = array_key_exists($field, $preview) ? (string) $preview[$field] : '—';

                if ($from !== $to) {
                    $diff[] = [
                        'scene' => (int) ($scene['order'] ?? $index + 1),
                        'field' => $field,
                        'persisted' => $from,
                        'preview' => $to,
                    ];
                }
            }
        }

        return $diff;
    }

    private function resolvePreviewSource(VideoScript $script): ?ScriptSource
    {
        $runs = AutomationRun::query()
            ->where('pipeline', 'script_agents.pipeline')
            ->latest('id')
            ->limit(50)
            ->get();

        foreach ($runs as $run) {
            $meta = $run->meta ?? [];

            if ((int) ($meta['video_script_id'] ?? 0) !== (int) $script->getKey()) {
                continue;
            }

            $subject = $run->subject;

            if ($subject instanceof StoryCluster || $subject instanceof News) {
                return app(ScriptSourceFactory::class)->fromModel(
                    $subject,
                    (int) $script->target_duration,
                    (string) $script->language,
                );
            }
        }

        $news = $script->news;

        if ($news instanceof News) {
            return app(ScriptSourceFactory::class)->fromModel(
                $news,
                (int) $script->target_duration,
                (string) $script->language,
            );
        }

        return null;
    }
}
