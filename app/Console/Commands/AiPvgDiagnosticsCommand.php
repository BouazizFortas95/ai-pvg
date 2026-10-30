<?php

namespace App\Console\Commands;

use App\Models\News;
use App\Models\VideoRender;
use App\Models\VideoScript;
use App\Services\AI\ScriptPipeline\ScriptAgentsGate;
use App\Services\Automation\AutopilotGateService;
use App\Services\Automation\BudgetGuard;
use App\Services\Automation\Triage\TriageGate;
use App\Services\Publishing\PublishingGate;
use App\Support\HyperFramesRuntime;
use App\Support\RemotionRuntime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AiPvgDiagnosticsCommand extends Command
{
    protected $signature = 'ai-pvg:diagnostics';

    protected $description = 'Read-only health check for AI-PVG pipeline';

    public function handle(BudgetGuard $budgetGuard): int
    {
        $this->info('=== AI-PVG DIAGNOSTICS (READ-ONLY) ===');
        $m = Schema::hasTable('migrations');
        $this->line('Migrations table: '.($m ? 'OK' : 'MISSING'));
        $this->line('Queue connection: '.config('queue.default'));
        $this->line('Remotion CLI: '.(is_file(RemotionRuntime::cliPath()) ? 'YES' : 'NO'));
        $this->line('Chrome headless: '.(RemotionRuntime::chromePath() ? 'YES' : 'NO'));
        $this->line('FFprobe: '.(RemotionRuntime::ffprobePath() ? 'YES' : 'NO'));
        $this->line('FFmpeg: '.(RemotionRuntime::ffmpegPath() ? 'YES' : 'NO'));
        $hf = HyperFramesRuntime::status();
        $this->line('HyperFrames dir: '.(is_dir($hf['directory']) ? 'YES' : 'NO'));
        $this->line('HyperFrames Node: '.($hf['node'] ?? 'MISSING'));
        $this->line('HyperFrames CLI: '.($hf['cli'] ?? 'MISSING'));
        $this->line('HyperFrames FFmpeg (full): '.($hf['ffmpeg'] ?? 'MISSING'));
        $this->line('HyperFrames FFmpeg setparams: '.(HyperFramesRuntime::ffmpegSupportsSetparams() ? 'YES' : 'NO'));
        $this->line('HyperFrames gate: '.($hf['enabled'] ? 'ENABLED' : 'disabled')
            .' (flag='.$hf['flag'].'='.($hf['flag_enabled'] ? 'on' : 'off').')');
        $this->line('HyperFrames toolchain: '.($hf['available'] ? 'READY' : 'INCOMPLETE — '.$hf['reason']));
        $dir = storage_path('app/video_out');
        $this->line('Output dir writable: '.(is_writable($dir) ? 'YES' : 'NO'));
        $this->line('AI provider configured: '.(config('ai.provider') ? 'YES' : 'NO'));
        $this->line('TTS provider: '.(config('tts.provider', 'mock')));
        $this->line('YouTube configured: '.(config('youtube.client_id') ? 'YES (token hidden)' : 'NO'));
        $this->line('DB records: news='.News::count()
            .'; scripts='.VideoScript::count()
            .'; renders='.VideoRender::count()
            .'; youtube_publishing_states='.$this->tableCount('youtube_publishing_states'));

        $this->diagnoseAutomation($budgetGuard);
        $this->diagnoseTriage();
        $this->diagnoseScriptAgents();

        $this->info('=== END DIAGNOSTICS ===');

        return 0;
    }

    private function diagnoseAutomation(BudgetGuard $budgetGuard): void
    {
        $tables = [
            'automation_runs',
            'automation_stage_runs',
            'ai_usage_ledger',
            'budget_periods',
            'approval_gates',
            'provider_health_events',
            'feature_flags',
        ];

        $present = [];
        $missing = [];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $present[] = $table;
            } else {
                $missing[] = $table;
            }
        }

        $this->line('Automation enabled: '.(config('automation.enabled') ? 'YES' : 'NO')
            .' (dry-run: '.(config('automation.dry_run') ? 'YES' : 'NO').')');
        $this->line('Automation tables: '.count($present).'/'.count($tables).' OK'
            .($missing === [] ? '' : '; missing='.implode(',', $missing)));

        if (Schema::hasTable('automation_runs')) {
            $runs = DB::table('automation_runs')
                ->select('status', DB::raw('count(*) as aggregate'))
                ->groupBy('status')
                ->pluck('aggregate', 'status');

            $this->line('Automation runs: total='.DB::table('automation_runs')->count()
                .'; pending='.(int) ($runs['pending'] ?? 0)
                .'; running='.(int) ($runs['running'] ?? 0)
                .'; paused='.(int) ($runs['paused'] ?? 0)
                .'; completed='.(int) ($runs['completed'] ?? 0)
                .'; failed='.(int) ($runs['failed'] ?? 0)
                .'; cancelled='.(int) ($runs['cancelled'] ?? 0));
        } else {
            $this->line('Automation runs: unavailable (table missing)');
        }

        if (Schema::hasTable('automation_stage_runs')) {
            $this->line('Automation stages: total='.DB::table('automation_stage_runs')->count());
        } else {
            $this->line('Automation stages: unavailable (table missing)');
        }

        if (Schema::hasTable('ai_usage_ledger')) {
            $this->line('AI usage ledger: entries='.DB::table('ai_usage_ledger')->count()
                .'; cost_micros='.(int) DB::table('ai_usage_ledger')->sum('cost_micros'));
        } else {
            $this->line('AI usage ledger: unavailable (table missing)');
        }

        if (Schema::hasTable('budget_periods')) {
            $snapshot = $budgetGuard->snapshot();
            $this->line('Budget: period='.($snapshot['period_start'] ?? 'not-initialized')
                .'; limit_micros='.$snapshot['limit_micros']
                .'; spent_micros='.$snapshot['spent_micros']
                .'; reserved_micros='.$snapshot['reserved_micros']
                .'; remaining_micros='.$snapshot['remaining_micros']
                .'; breaker='.$snapshot['breaker_state']);
        } else {
            $this->line('Budget: unavailable (table missing)');
        }

        if (Schema::hasTable('approval_gates')) {
            $approvals = DB::table('approval_gates')
                ->select('status', DB::raw('count(*) as aggregate'))
                ->groupBy('status')
                ->pluck('aggregate', 'status');

            $this->line('Approvals: pending='.(int) ($approvals['pending'] ?? 0)
                .'; approved='.(int) ($approvals['approved'] ?? 0)
                .'; rejected='.(int) ($approvals['rejected'] ?? 0)
                .'; expired='.(int) ($approvals['expired'] ?? 0)
                .'; dual_public_publish='.(config('automation.approval.require_dual_public_publish') ? 'YES' : 'NO'));
        } else {
            $this->line('Approvals: unavailable (table missing)');
        }

        if (Schema::hasTable('provider_health_events')) {
            $events = DB::table('provider_health_events')
                ->where('created_at', '>=', now()->subDay())
                ->select('provider', DB::raw('count(*) as aggregate'))
                ->groupBy('provider')
                ->pluck('aggregate', 'provider');

            $last = DB::table('provider_health_events')->latest('created_at')->first();

            $this->line('Provider health: events_24h='.(int) $events->sum()
                .'; providers='.($events->isEmpty() ? 'none' : $events->map(
                    fn ($count, string $provider): string => $provider.'='.(int) $count,
                )->implode(','))
                .'; last='.($last ? $last->provider.'/'.$last->event_type : 'none'));
        } else {
            $this->line('Provider health: unavailable (table missing)');
        }

        if (Schema::hasTable('feature_flags')) {
            $this->line('Feature flags: total='.DB::table('feature_flags')->count()
                .'; enabled='.DB::table('feature_flags')->where('enabled', true)->count());
        } else {
            $this->line('Feature flags: unavailable (table missing)');
        }
    }

    private function diagnoseTriage(): void
    {
        $tables = ['news_scores', 'story_clusters', 'story_cluster_news'];
        $present = [];
        $missing = [];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $present[] = $table;
            } else {
                $missing[] = $table;
            }
        }

        $gate = app(TriageGate::class);

        $this->line('Triage enabled: '.($gate->enabled() ? 'YES' : 'NO')
            .' (flag='.$gate->flagKey().')');
        $this->line('Triage tables: '.count($present).'/'.count($tables).' OK'
            .($missing === [] ? '' : '; missing='.implode(',', $missing)));

        if ($missing === []) {
            $this->line('Triage data: news_scores='.$this->tableCount('news_scores')
                .'; story_clusters='.$this->tableCount('story_clusters')
                .'; story_cluster_news='.$this->tableCount('story_cluster_news'));
        } else {
            $this->line('Triage data: unavailable (tables missing)');
        }
    }

    private function diagnoseScriptAgents(): void
    {
        $tables = ['script_variants', 'script_fact_checks'];
        $present = [];
        $missing = [];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $present[] = $table;
            } else {
                $missing[] = $table;
            }
        }

        $gate = app(ScriptAgentsGate::class);

        $this->line('Script agents enabled: '.($gate->enabled() ? 'YES' : 'NO')
            .' (flag='.$gate->flagKey()
            .'; use_ai='.(config('automation.script_agents.use_ai') ? 'YES' : 'NO').')');
        $this->line('Script agent tables: '.count($present).'/'.count($tables).' OK'
            .($missing === [] ? '' : '; missing='.implode(',', $missing)));

        if ($missing === []) {
            $hooks = DB::table('script_variants')->where('variant_type', 'hook')->count();
            $pendingGates = Schema::hasTable('approval_gates')
                ? DB::table('approval_gates')
                    ->where('gate_type', 'script_fact_check')
                    ->where('status', 'pending')
                    ->count()
                : 0;

            $this->line('Script agent data: script_variants='.$this->tableCount('script_variants')
                .'; hooks='.$hooks
                .'; script_fact_checks='.$this->tableCount('script_fact_checks')
                .'; pending_fact_check_gates='.$pendingGates);
        } else {
            $this->line('Script agent data: unavailable (tables missing)');
        }

        $captionTables = ['caption_tracks', 'audio_assets'];
        $captionPresent = array_filter($captionTables, fn ($t) => Schema::hasTable($t));
        $this->line('Caption agents enabled: '.(config('automation.captions.enabled', false) ? 'YES' : 'NO')
            .' (flag='.config('automation.captions.feature_flag').'; lang='.config('automation.captions.language_default', 'en').')');
        $this->line('Caption tables: '.count($captionPresent).'/'.count($captionTables).' OK');
        $this->line('Caption data: caption_tracks='.$this->tableCount('caption_tracks')
            .'; audio_assets='.$this->tableCount('audio_assets'));

        $assetTables = ['visual_assets', 'scene_assets'];
        $assetPresent = array_filter($assetTables, fn ($t) => Schema::hasTable($t));
        $this->line('Visual assets enabled: '.(config('automation.assets.enabled', false) ? 'YES' : 'NO')
            .' (flag='.config('automation.assets.feature_flag').'; allowed='.count(config('automation.assets.allowed_domains', [])).')');
        $this->line('Visual asset tables: '.count($assetPresent).'/'.count($assetTables).' OK');
        $this->line('Visual asset data: visual_assets='.$this->tableCount('visual_assets')
            .'; scene_assets='.$this->tableCount('scene_assets'));

        $pubTables = ['publishing_approval_sessions', 'co_pilot_review_sessions'];
        $pubPresent = array_filter($pubTables, fn ($t) => Schema::hasTable($t));
        $publishingGate = app(PublishingGate::class);
        $autopilot = app(AutopilotGateService::class)->snapshot();
        $approvalPending = Schema::hasTable('publishing_approval_sessions')
            ? DB::table('publishing_approval_sessions')->where('status', 'pending')->count()
            : 0;
        $approvalApproved = Schema::hasTable('publishing_approval_sessions')
            ? DB::table('publishing_approval_sessions')->where('status', 'approved')->count()
            : 0;

        $this->line('Publishing / Co-Pilot enabled: '.(config('automation.publishing.enabled', false) ? 'YES' : 'NO')
            .' (flag='.$publishingGate->flagKey()
            .'; gate='.($publishingGate->enabled() ? 'OPEN' : 'CLOSED')
            .'; dual_public_publish=ENFORCED)');
        $this->line('Publishing / Co-Pilot tables: '.count($pubPresent).'/'.count($pubTables).' OK');
        $this->line('Publishing / Co-Pilot data: approval_sessions='.$this->tableCount('publishing_approval_sessions')
            .'; pending='.$approvalPending
            .'; approved='.$approvalApproved
            .'; co_pilot_sessions='.$this->tableCount('co_pilot_review_sessions'));
        $this->line('Autopilot gate: '.($autopilot['allowed'] ? 'READY' : 'BLOCKED')
            .'; blocked='.($autopilot['blocked'] === [] ? 'none' : implode(',', $autopilot['blocked']))
            .'; budget_remaining_micros='.$autopilot['budget_remaining_micros']
            .'; breaker='.$autopilot['breaker_state']);
    }

    private function tableCount(string $table): int
    {
        return Schema::hasTable($table) ? DB::table($table)->count() : 0;
    }
}
