<?php

namespace App\Livewire;

use App\Enums\UserRole;
use App\Models\News;
use App\Models\NewsScore;
use App\Models\StoryCluster;
use App\Models\VideoScript;
use App\Services\Automation\Greenlight\AutoGreenlightService;
use App\Services\Automation\Triage\TriageGate;
use App\Services\ThemeEngine;
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

    public function render()
    {
        $triageEnabled = app(TriageGate::class)->enabled();
        $triageScores = collect();
        $triageClusters = collect();

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

        return view('studio.dashboard', [
            'stats' => $this->stats,
            'scripts' => VideoScript::with('news')->latest()->take(5)->get(),
            'news' => News::latest()->take(5)->get(),
            'triageEnabled' => $triageEnabled,
            'triageScores' => $triageScores,
            'triageClusters' => $triageClusters,
        ]);
    }
}
