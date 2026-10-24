<?php

namespace App\Console\Commands;

use App\Models\News;
use App\Models\VideoRender;
use App\Models\VideoScript;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class AiPvgDiagnosticsCommand extends Command
{
    protected $signature = 'ai-pvg:diagnostics';

    protected $description = 'Read-only health check for AI-PVG pipeline';

    public function handle(): int
    {
        $this->info('=== AI-PVG DIAGNOSTICS (READ-ONLY) ===');
        $m = Schema::hasTable('migrations');
        $this->line('Migrations table: '.($m ? 'OK' : 'MISSING'));
        $this->line('Queue connection: '.config('queue.default'));
        $this->line('Remotion dist exists: '.(is_dir('remotion/dist') ? 'YES' : 'NO'));
        $this->line('FFprobe available: '.(shell_exec('which ffprobe 2>/dev/null') ? 'YES' : 'NO (file checks only)'));
        $dir = storage_path('app/video_out');
        $this->line('Output dir writable: '.(is_writable($dir ?? storage_path('app')) ? 'YES' : 'NO'));
        $this->line('AI provider configured: '.(config('ai.provider') ? 'YES' : 'NO'));
        $this->line('TTS provider: '.(config('tts.provider', 'mock')));
        $this->line('YouTube configured: '.(config('youtube.client_id') ? 'YES (token hidden)' : 'NO'));
        $this->line('DB records: news='.News::count().'; scripts='.VideoScript::count().'; renders='.VideoRender::count());
        $this->info('=== END DIAGNOSTICS ===');

        return 0;
    }
}
