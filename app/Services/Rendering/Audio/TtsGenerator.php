<?php

namespace App\Services\Rendering\Audio;

use App\Exceptions\Rendering\AudioPipelineException;
use App\Services\Rendering\ProcessRunner;
use App\Services\TTS\TTSManager;
use App\Support\HyperFramesRuntime;
use Illuminate\Support\Facades\File;

/**
 * Local-first narration generation.
 *
 * Provider order (auto):
 *  1. `hyperframes tts` (Kokoro-82M, local, free) when its Python runtime exists.
 *  2. Windows SAPI / System.Speech when a matching installed voice exists.
 *  3. The configured TTS provider only if it is real (never the mock).
 *
 * The fallback exists because Kokoro needs Python + onnxruntime, while SAPI is
 * always present on Windows. No paid provider and no network call is made.
 */
class TtsGenerator
{
    /** @var array<string, string>|null */
    private ?array $systemVoices = null;

    private ?bool $kokoroAvailable = null;

    public function __construct(
        protected ProcessRunner $runner = new ProcessRunner,
        protected string $scriptPath = '',
    ) {
        $this->scriptPath = $scriptPath !== ''
            ? $scriptPath
            : resource_path('scripts/sapi_tts.ps1');
    }

    /**
     * @return array{path: string, duration: float, provider: string, voice: string, language: string}
     */
    public function synthesize(string $text, string $language, ?string $voice, string $outputPath): array
    {
        $text = trim($text);

        if ($text === '') {
            throw new AudioPipelineException('Cannot synthesize an empty narration line.');
        }

        $language = $this->normalizeLanguage($language);
        $provider = (string) config('automation.render.hyperframes.audio.tts_provider', 'auto');

        if ($provider === 'auto') {
            $provider = $this->availableProvider($language);
        }

        $result = match ($provider) {
            'hyperframes' => $this->synthesizeKokoro($text, $language, $voice, $outputPath),
            'sapi' => $this->synthesizeSapi($text, $language, $voice, $outputPath),
            'existing' => $this->synthesizeExisting($text, $language, $voice, $outputPath),
            default => throw new AudioPipelineException('Unknown TTS provider: '.$provider),
        };

        $this->normalizeWav($result['path']);

        $probe = app(AudioProbe::class);
        $duration = $probe->duration($result['path']);
        $volume = $probe->volume($result['path']);

        if ($duration < 0.25) {
            throw new AudioPipelineException('TTS produced too little audio ('.$duration.'s).');
        }

        if (($volume['mean_volume'] ?? -60) < -45) {
            throw new AudioPipelineException('TTS audio is effectively silent (mean '.$volume['mean_volume'].' dB).');
        }

        return [
            'path' => $result['path'],
            'duration' => round($duration, 3),
            'provider' => $result['provider'],
            'voice' => $result['voice'],
            'language' => $language,
        ];
    }

    public function availableProvider(string $language): string
    {
        if ($this->kokoroAvailable() && $this->kokoroSupports($language)) {
            return 'hyperframes';
        }

        if ($this->sapiVoiceFor($language) !== null) {
            return 'sapi';
        }

        $manager = new TTSManager((string) config('automation.providers.tts', 'mock'));

        if ($manager->getProviderName() !== 'mock' && $manager->supportsLanguage($language)) {
            return 'existing';
        }

        throw new AudioPipelineException(
            'No real local TTS voice is available for language "'.$language.'". '
            .'Install a Windows language voice pack, or install Kokoro (Python + kokoro-onnx), '
            .'or configure a non-mock TTS provider.'
        );
    }

    /**
     * @return array{path: string, provider: string, voice: string}
     */
    private function synthesizeKokoro(string $text, string $language, ?string $voice, string $outputPath): array
    {
        $cli = HyperFramesRuntime::cliPath();

        if ($cli === null) {
            throw new AudioPipelineException('HyperFrames CLI is missing; cannot run Kokoro TTS.');
        }

        $textFile = $outputPath.'.txt';
        File::ensureDirectoryExists(dirname($outputPath));
        File::put($textFile, $text);

        $voice = $voice ?: $this->kokoroVoice($language);
        $lang = $this->kokoroLanguage($language);

        $command = [
            ...HyperFramesRuntime::commandPrefix(),
            $cli, 'tts', $textFile,
            '--voice', $voice,
            '--lang', $lang,
            '--output', $outputPath,
        ];

        $result = $this->runner->run($command, dirname($outputPath), HyperFramesRuntime::environment(), 300);
        File::delete($textFile);

        if (! $result->successful || ! is_file($outputPath)) {
            throw new AudioPipelineException('Kokoro TTS failed: '.trim($result->errorOutput ?: $result->output));
        }

        return ['path' => $outputPath, 'provider' => 'hyperframes-kokoro', 'voice' => $voice];
    }

    /**
     * @return array{path: string, provider: string, voice: string}
     */
    private function synthesizeSapi(string $text, string $language, ?string $voice, string $outputPath): array
    {
        $selected = $voice ?: $this->sapiVoiceFor($language);

        if ($selected === null) {
            throw new AudioPipelineException('No installed SAPI voice matches language "'.$language.'".');
        }

        $textFile = $outputPath.'.txt';
        File::ensureDirectoryExists(dirname($outputPath));
        File::put($textFile, $text);

        $command = [
            'powershell', '-NoProfile', '-ExecutionPolicy', 'Bypass',
            '-File', $this->scriptPath,
            '-Out', $outputPath,
            '-TextFile', $textFile,
            '-Voice', $selected,
            '-Rate', (string) (int) config('automation.render.hyperframes.audio.sapi_rate', 1),
        ];

        $result = $this->runner->run($command, dirname($outputPath), HyperFramesRuntime::environment(), 300);
        File::delete($textFile);

        if (! $result->successful || ! is_file($outputPath)) {
            throw new AudioPipelineException('SAPI TTS failed: '.trim($result->errorOutput ?: $result->output));
        }

        return ['path' => $outputPath, 'provider' => 'windows-sapi', 'voice' => $selected];
    }

    /**
     * @return array{path: string, provider: string, voice: string}
     */
    private function synthesizeExisting(string $text, string $language, ?string $voice, string $outputPath): array
    {
        $manager = new TTSManager((string) config('automation.providers.tts', 'mock'));
        $result = $manager->synthesize($text, ['language' => $language, 'voice' => $voice]);
        $path = (string) ($result['path'] ?? '');

        if ($path === '' || ! is_file($path)) {
            throw new AudioPipelineException('Configured TTS provider did not produce a file.');
        }

        File::ensureDirectoryExists(dirname($outputPath));
        File::copy($path, $outputPath);

        return [
            'path' => $outputPath,
            'provider' => $manager->getProviderName(),
            'voice' => (string) ($voice ?? 'default'),
        ];
    }

    public function sapiVoiceFor(string $language): ?string
    {
        $base = explode('-', $language)[0];
        $voices = $this->systemVoices();

        foreach ($voices as $name => $culture) {
            if (str_starts_with(strtolower($culture), $base.'-')) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    public function systemVoices(): array
    {
        if ($this->systemVoices !== null) {
            return $this->systemVoices;
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            return $this->systemVoices = [];
        }

        $script = <<<'PS'
Add-Type -AssemblyName System.Speech
$s = New-Object System.Speech.Synthesis.SpeechSynthesizer
$s.GetInstalledVoices() | ForEach-Object { $_.VoiceInfo.Name + '|' + $_.VoiceInfo.Culture.Name }
PS;

        $result = $this->runner->run(
            ['powershell', '-NoProfile', '-Command', $script],
            null,
            HyperFramesRuntime::environment(),
            60,
        );

        if (! $result->successful) {
            return $this->systemVoices = [];
        }

        $voices = [];

        foreach (preg_split('/\R/', $result->output) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || ! str_contains($line, '|')) {
                continue;
            }

            [$name, $culture] = explode('|', $line, 2);
            $voices[trim($name)] = trim($culture);
        }

        return $this->systemVoices = $voices;
    }

    private function kokoroAvailable(): bool
    {
        if ($this->kokoroAvailable !== null) {
            return $this->kokoroAvailable;
        }

        $result = $this->runner->run(
            ['python', '-c', 'import kokoro_onnx, soundfile'],
            null,
            HyperFramesRuntime::environment(),
            30,
        );

        return $this->kokoroAvailable = $result->successful;
    }

    private function kokoroSupports(string $language): bool
    {
        return array_key_exists($this->normalizeLanguage($language), $this->kokoroVoices());
    }

    /**
     * @return array<string, string>
     */
    private function kokoroVoices(): array
    {
        return [
            'en' => 'af_heart',
            'en-gb' => 'bf_emma',
            'es' => 'ef_dora',
            'fr' => 'ff_siwis',
            'ja' => 'jf_alpha',
            'zh' => 'zf_xiaobei',
        ];
    }

    private function kokoroVoice(string $language): string
    {
        return $this->kokoroVoices()[$this->normalizeLanguage($language)] ?? 'af_heart';
    }

    private function kokoroLanguage(string $language): string
    {
        return match ($this->normalizeLanguage($language)) {
            'en' => 'en-us',
            'en-gb' => 'en-gb',
            'fr' => 'fr-fr',
            'es' => 'es',
            'ja' => 'ja',
            'zh' => 'zh',
            default => 'en-us',
        };
    }

    private function normalizeLanguage(string $language): string
    {
        $language = strtolower(trim($language));

        return match (true) {
            str_starts_with($language, 'en-gb') => 'en-gb',
            str_starts_with($language, 'en') => 'en',
            str_starts_with($language, 'fr') => 'fr',
            str_starts_with($language, 'es') => 'es',
            str_starts_with($language, 'ja') => 'ja',
            str_starts_with($language, 'zh') => 'zh',
            default => explode('-', $language)[0],
        };
    }

    private function normalizeWav(string $path): void
    {
        $ffmpeg = HyperFramesRuntime::ffmpegPath();

        if ($ffmpeg === null) {
            throw new AudioPipelineException('FFmpeg is required to normalize narration audio.');
        }

        $temporary = $path.'.normalized.wav';

        $result = $this->runner->run([
            $ffmpeg, '-y', '-hide_banner', '-nostats', '-i', $path,
            '-ar', '48000', '-ac', '1', '-c:a', 'pcm_s16le', $temporary,
        ], dirname($path), HyperFramesRuntime::environment(), 120);

        if (! $result->successful || ! is_file($temporary)) {
            File::delete($temporary);

            throw new AudioPipelineException('Could not normalize narration with FFmpeg: '.trim($result->errorOutput));
        }

        File::move($temporary, $path);
    }
}
