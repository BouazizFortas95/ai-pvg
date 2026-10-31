<?php

namespace App\Services\Rendering\Audio;

use App\Services\Rendering\ProcessRunner;
use App\Support\HyperFramesRuntime;
use Illuminate\Support\Facades\File;

/**
 * Word-level timings via `hyperframes transcribe` (Parakeet or Whisper).
 *
 * Language rules:
 *  - English uses the installed Parakeet model when present.
 *  - Non-English never uses a `.en` Whisper model; it requests a multilingual
 *    model instead, and reports `skipped` if no engine covers the language.
 *  - When transcription is unavailable, captions are omitted rather than
 *    invented from an estimate.
 */
class TranscriptionService
{
    public function __construct(
        protected ProcessRunner $runner = new ProcessRunner,
    ) {}

    /**
     * @return array{ok: bool, engine: ?string, words: list<array{word: string, startMs: int, endMs: int}>, reason: ?string}
     */
    public function transcribe(string $audioPath, string $language, string $projectDir): array
    {
        $cli = HyperFramesRuntime::cliPath();

        if ($cli === null || ! is_file($audioPath)) {
            return ['ok' => false, 'engine' => null, 'words' => [], 'reason' => 'HyperFrames CLI or narration file is missing.'];
        }

        $language = strtolower(trim($language));
        $base = explode('-', $language)[0];
        $command = [
            ...HyperFramesRuntime::commandPrefix(),
            'transcribe', $audioPath,
            '--language', $base,
            '--json',
            '--optional',
        ];

        if ($base !== 'en') {
            // Never use the English-only models for non-English audio.
            $command[] = '--engine';
            $command[] = 'auto';
            $command[] = '--model';
            $command[] = (string) config('automation.render.hyperframes.audio.whisper_model', 'small');
        }

        $result = $this->runner->run($command, $projectDir, HyperFramesRuntime::environment(), 900);
        $output = $result->output."\n".$result->errorOutput;
        $words = $this->parseWords($output);

        if ($words === []) {
            $words = $this->readTranscript($audioPath, $projectDir);
        }

        if ($words === []) {
            $reason = 'Transcription unavailable';

            if (preg_match('/"reason"\s*:\s*"([^"]+)"/', $output, $match) === 1) {
                $reason .= ': '.$match[1];
            }

            return ['ok' => false, 'engine' => null, 'words' => [], 'reason' => $reason];
        }

        $engine = preg_match('/"engine"\s*:\s*"([^"]+)"/', $output, $match) === 1 ? $match[1] : null;

        return ['ok' => true, 'engine' => $engine, 'words' => $words, 'reason' => null];
    }

    /**
     * Parse the `{"type":"words","words":[...]}` JSON line emitted by the CLI.
     *
     * @return list<array{word: string, startMs: int, endMs: int}>
     */
    public function parseWords(string $output): array
    {
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || ! str_contains($line, '"words"')) {
                continue;
            }

            $data = json_decode($line, true);

            if (is_array($data) && isset($data['words']) && is_array($data['words'])) {
                return $this->normalizeWords($data['words']);
            }
        }

        return [];
    }

    /**
     * @return list<array{word: string, startMs: int, endMs: int}>
     */
    private function readTranscript(string $audioPath, string $projectDir): array
    {
        $candidates = [
            dirname($audioPath).'/transcript.json',
            $projectDir.'/transcript.json',
        ];

        foreach ($candidates as $candidate) {
            if (! is_file($candidate)) {
                continue;
            }

            $data = json_decode((string) File::get($candidate), true);

            if (is_array($data)) {
                $words = $this->normalizeWords($data);

                if ($words !== []) {
                    return $words;
                }
            }
        }

        return [];
    }

    /**
     * @param  array<int, mixed>  $words
     * @return list<array{word: string, startMs: int, endMs: int}>
     */
    private function normalizeWords(array $words): array
    {
        $normalized = [];

        foreach ($words as $word) {
            if (! is_array($word)) {
                continue;
            }

            $text = trim((string) ($word['text'] ?? $word['word'] ?? ''));

            if ($text === '') {
                continue;
            }

            $start = (float) ($word['start'] ?? 0);
            $end = (float) ($word['end'] ?? $start + 0.2);

            if ($end <= $start) {
                $end = $start + 0.2;
            }

            $normalized[] = [
                'word' => $text,
                'startMs' => (int) round($start * 1000),
                'endMs' => (int) round($end * 1000),
            ];
        }

        usort($normalized, static fn (array $a, array $b): int => $a['startMs'] <=> $b['startMs']);

        return $normalized;
    }
}
