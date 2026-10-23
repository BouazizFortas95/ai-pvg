<?php

namespace App\Livewire;

use App\Models\News;
use App\Models\VideoScript;
use App\Services\ThemeEngine;
use Livewire\Component;

class StudioDashboard extends Component
{
    public string $themeName = 'Cyber Dark';

    public string $primaryColor = '#00d4ff';

    public string $mode = 'dark';

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

    public function render()
    {
        return view('studio.dashboard', [
            'stats' => $this->stats,
            'scripts' => VideoScript::with('news')->latest()->take(5)->get(),
            'news' => News::latest()->take(5)->get(),
        ]);
    }
}
