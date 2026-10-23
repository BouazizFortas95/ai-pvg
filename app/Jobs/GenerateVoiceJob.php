<?php
namespace App\Jobs;
use App\Models\VoiceGeneration;
use App\Services\VoiceGenerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
class GenerateVoiceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    public int $tries = 2;
    public int $timeout = 300;
    public array $backoff = [30,120];
    public function __construct(public int $voiceGenerationId) {}
    public function uniqueId(): string { return 'voice-gen-'.$this->voiceGenerationId; }
    public function uniqueFor(): int { return 300; }
    public function handle(): void
    {
        $gen = VoiceGeneration::find($this->voiceGenerationId);
        if (! $gen) return;
        if ($gen->status === 'completed') return;
        $service = app(VoiceGenerationService::class);
        $service->generate($gen->videoScript, $gen->language, $gen->voice);
    }
}
