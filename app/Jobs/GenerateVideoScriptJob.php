<?php

namespace App\Jobs;

use App\Enums\VideoScriptStatus;
use App\Models\VideoScript;
use App\Services\AI\VideoScriptGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class GenerateVideoScriptJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public array $backoff = [30, 120];

    public function __construct(public int $videoScriptId) {}

    public function uniqueId(): string
    {
        return 'video-script-gen-'.$this->videoScriptId;
    }

    public function uniqueFor(): int
    {
        return 300;
    }

    public function handle(): void
    {
        $script = VideoScript::find($this->videoScriptId);
        if (! $script) {
            return;
        }
        if ($script->status === VideoScriptStatus::Approved->value) {
            return;
        }
        if ($script->status === VideoScriptStatus::Generating->value) {
            $lock = Cache::lock('video-gen-'.$this->videoScriptId, 300);
            if (! $lock->get()) {
                return;
            }
        }
        $script->update(['status' => VideoScriptStatus::Generating->value]);
        $generator = app(VideoScriptGenerator::class);
        try {
            $updated = $generator->generateForNews($script->news, $script->target_duration, $script->language);
            $updated->update(['status' => VideoScriptStatus::Generated->value]);
        } catch (\Exception $e) {
            $script->update([
                'status' => VideoScriptStatus::Failed->value,
                'error_message' => substr($e->getMessage(), 0, 500),
            ]);
        }
    }
}
