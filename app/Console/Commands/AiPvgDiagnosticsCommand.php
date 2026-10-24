<?php

namespace App\Console\Commands;

use App\Models\News;
use App\Models\VideoRender;
use App\Models\VideoScript;
use App\Services\Automation\BudgetGuard;
use App\Services\Automation\Triage\TriageGate;
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

    private function tableCount(string $table): int
    {
        return Schema::hasTable($table) ? DB::table($table)->count() : 0;
    }
}
