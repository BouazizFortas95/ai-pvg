<?php

namespace App\Services\AI;

use App\Enums\VideoScriptStatus;
use App\Models\News;
use App\Models\VideoScript;

class VideoScriptGenerator
{
    protected AIManager $ai;

    public function __construct(?AIManager $ai = null)
    {
        $this->ai = $ai ?? new AIManager(config('services.ai.default', 'ollama'));
    }

    public function generateForNews(News $news, int $duration, string $language = 'en'): VideoScript
    {
        $script = VideoScript::create([
            'news_id' => $news->getKey(),
            'title' => $news->title,
            'language' => $language,
            'target_duration' => $duration,
            'status' => VideoScriptStatus::Generating->value,
            'ai_provider' => $this->ai->getProviderName(),
            'ai_model' => config('services.ai.model', 'default'),
        ]);

        try {
            $prompt = $this->buildPrompt($news, $duration, $language);
            $response = $this->ai->generate($prompt, [
                'temperature' => 0.5,
                'max_tokens' => 2500,
                'system_prompt' => 'Generate structured video scripts as JSON with title, language, target_duration, narration, and scenes array. Each scene needs order, type, duration, headline, description, narration, and visual_keywords.',
            ]);

            $structured = $this->parseResponse($response, $duration, $language);
            $structured['status'] = VideoScriptStatus::Generated->value;
            $structured['generated_at'] = now();

            $script->update($structured);
        } catch (\Exception $e) {
            $script->update([
                'status' => VideoScriptStatus::Failed->value,
                'error_message' => substr($e->getMessage(), 0, 500),
            ]);
        }

        return $script->fresh();
    }

    protected function buildPrompt(News $news, int $duration, string $language): string
    {
        $context = trim(($news->summary ?? $news->content ?? $news->title) ?? '');
        $map = ['ar' => 'Arabic', 'en' => 'English', 'fr' => 'French'];
        $langName = $map[$language] ?? $language;

        return "Generate a {$duration}-second video script in {$langName} for this news article.\nTitle: {$news->title}\nContent: {$context}\nStructure: JSON with title, language ({$language}), target_duration ({$duration}), narration, scenes array. Each scene: order, type (intro/segment/conclusion), duration (sum to {$duration}), headline, description, narration, visual_keywords (array). Ensure factual accuracy from source text.";
    }

    protected function parseResponse(string $response, int $duration, string $language): array
    {
        $clean = trim($response);
        $start = strpos($clean, '{');
        $end = strrpos($clean, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $jsonStr = substr($clean, $start, $end - $start + 1);
        } else {
            $jsonStr = $clean;
        }
        $data = json_decode($jsonStr, true);
        if (! is_array($data)) {
            throw new \RuntimeException('AI response is not valid JSON.');
        }
        $scenes = $data['scenes'] ?? [];
        $totalDuration = array_sum(array_column($scenes, 'duration'));
        if (abs($totalDuration - $duration) > 5) {
            throw new \RuntimeException('Scene durations do not match target duration.');
        }
        $narration = $data['narration'] ?? $data['narration_text'] ?? '';
        $scenes = array_map(fn ($s) => [
            'order' => (int) ($s['order'] ?? 0),
            'type' => in_array($s['type'] ?? '', ['intro', 'segment', 'conclusion', 'outro']) ? $s['type'] : 'segment',
            'duration' => max(1, (int) ($s['duration'] ?? 0)),
            'headline' => (string) ($s['headline'] ?? ''),
            'description' => (string) ($s['description'] ?? ''),
            'narration' => (string) ($s['narration'] ?? ''),
            'visual_keywords' => is_array($s['visual_keywords'] ?? null) ? $s['visual_keywords'] : [],
        ], $scenes);
        $scenes = collect($scenes)->sortBy('order')->values()->toArray();

        return [
            'narration' => is_string($narration) ? $narration : '',
            'scenes' => $scenes,
            'estimated_duration' => $duration,
        ];
    }
}
