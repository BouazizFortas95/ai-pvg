<?php

namespace App\Services\AI\ScriptPipeline;

use Illuminate\Support\Str;

/**
 * Deterministic narrative architect. It turns a grounded fact sheet and a
 * selected hook into ordered scenes with a strict directive payload.
 */
class NarrativeArchitectAgent
{
    /**
     * @return list<array<string, mixed>>
     */
    public function compose(ScriptSource $source, ?string $hook = null): array
    {
        $target = max(10, $source->targetDuration);
        $maxScene = max(3, (int) config('automation.script_agents.max_scene_seconds', 12));
        $introDuration = min(5, max(2, intdiv($target, 8)));
        $outroDuration = $introDuration;
        $remaining = max(1, $target - $introDuration - $outroDuration);
        $claims = $source->claims !== [] ? $source->claims : [[
            'claim' => (string) $source->title,
            'source_news_ids' => [$source->primaryNewsId()],
        ]];
        $beats = $this->beats($claims, $remaining, $maxScene);
        $durations = $this->allocate($remaining, count($beats));
        $primaryId = $source->primaryNewsId();
        $allIds = $source->newsIds();
        $hook = trim((string) $hook);
        $introNarration = trim(($hook !== '' ? $hook.' ' : '').($source->summary ?: $source->title));
        $scenes = [];

        $scenes[] = [
            'order' => 1,
            'type' => 'intro',
            'duration' => $introDuration,
            'headline' => Str::limit($hook !== '' ? $hook : $source->title, 80, ''),
            'description' => 'Source-attributed introduction to the grounded story.',
            'narration' => $introNarration,
            'claim' => $source->title,
            'visual_keywords' => $this->keywords($source->title),
            'source_news_ids' => [$primaryId],
            'directives' => $this->directives(
                type: 'intro',
                duration: $introDuration,
                order: 1,
                headline: $hook !== '' ? $hook : $source->title,
                visualText: $hook !== '' ? $hook : $source->title,
            ),
        ];

        $order = 2;

        foreach ($beats as $index => $beat) {
            $duration = $durations[$index];
            $claim = (string) $beat['claim'];
            $narration = (string) $beat['narration'];

            $scenes[] = [
                'order' => $order,
                'type' => 'segment',
                'duration' => $duration,
                'headline' => Str::limit($claim, 80, ''),
                'description' => 'Grounded claim sourced from news ids: '
                    .implode(', ', $beat['source_news_ids']).'.',
                'narration' => $narration,
                'claim' => $claim,
                'visual_keywords' => $this->keywords($claim),
                'source_news_ids' => $beat['source_news_ids'],
                'directives' => $this->directives(
                    type: 'segment',
                    duration: $duration,
                    order: $order,
                    headline: $claim,
                    visualText: $claim,
                ),
            ];

            $order++;
        }

        $conclusionClaim = trim((string) ($source->summary ?: $source->title));

        $scenes[] = [
            'order' => $order,
            'type' => 'conclusion',
            'duration' => $outroDuration,
            'headline' => 'Why this story matters',
            'description' => 'Close with the verified source context.',
            'narration' => $conclusionClaim,
            'claim' => $conclusionClaim,
            'visual_keywords' => $this->keywords($conclusionClaim),
            'source_news_ids' => $allIds,
            'directives' => $this->directives(
                type: 'conclusion',
                duration: $outroDuration,
                order: $order,
                headline: $conclusionClaim,
                visualText: $conclusionClaim,
            ),
        ];

        return $scenes;
    }

    /**
     * @param  list<array{claim: string, source_news_ids: list<int>}>  $claims
     * @return list<array{claim: string, narration: string, source_news_ids: list<int>}>
     */
    private function beats(array $claims, int $remaining, int $maxScene): array
    {
        $claims = array_slice($claims, 0, max(1, $remaining));
        $beats = array_map(fn (array $claim): array => [
            'claim' => trim($claim['claim']),
            'narration' => trim($claim['claim']),
            'source_news_ids' => array_values(array_map('intval', $claim['source_news_ids'])),
        ], $claims);

        while (count($beats) < 40
            && intdiv($remaining, max(1, count($beats))) > $maxScene) {
            $longest = null;
            $longestLength = 0;

            foreach ($beats as $index => $beat) {
                $length = mb_strlen($beat['narration']);

                if ($length > $longestLength) {
                    $longest = $index;
                    $longestLength = $length;
                }
            }

            if ($longest === null || $longestLength < 2) {
                break;
            }

            $beat = $beats[$longest];
            $parts = $this->splitText($beat['narration']);

            if ($parts === null) {
                break;
            }

            array_splice($beats, $longest, 1, [
                ['claim' => $beat['claim'], 'narration' => $parts[0], 'source_news_ids' => $beat['source_news_ids']],
                ['claim' => $beat['claim'], 'narration' => $parts[1], 'source_news_ids' => $beat['source_news_ids']],
            ]);
        }

        return $beats;
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function splitText(string $text): ?array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];

        if (count($words) < 2) {
            return null;
        }

        $middle = (int) ceil(count($words) / 2);
        $left = trim(implode(' ', array_slice($words, 0, $middle)));
        $right = trim(implode(' ', array_slice($words, $middle)));

        if ($left === '' || $right === '') {
            return null;
        }

        return [$left, $right];
    }

    /**
     * @return list<int>
     */
    private function allocate(int $total, int $scenes): array
    {
        $scenes = max(1, $scenes);
        $base = intdiv($total, $scenes);
        $remainder = $total % $scenes;
        $durations = [];

        for ($index = 0; $index < $scenes; $index++) {
            $durations[] = max(1, $base + ($index < $remainder ? 1 : 0));
        }

        return $durations;
    }

    /**
     * @return array<string, string|int>
     */
    private function directives(string $type, int $duration, int $order, string $headline, string $visualText): array
    {
        $cameraMotions = ['slow_in', 'pan_left', 'push_in', 'pan_right', 'slow_out', 'static'];
        $sfx = match ($type) {
            'intro' => 'whoosh',
            'conclusion' => 'riser',
            default => $order % 2 === 0 ? 'impact' : 'none',
        };
        $tone = match ($type) {
            'intro' => 'urgent',
            'conclusion' => 'serious',
            default => $order % 2 === 0 ? 'confident' : 'curious',
        };

        return [
            'camera_motion' => $cameraMotions[($order - 1) % count($cameraMotions)],
            'b_roll_query' => Str::limit(implode(' ', $this->keywords($visualText)), 110, ''),
            'highlight_text' => Str::limit(trim($headline), 70, ''),
            'sfx_cue' => $sfx,
            'emotion_tone' => $tone,
            'duration_seconds' => $duration,
        ];
    }

    /**
     * @return list<string>
     */
    private function keywords(string $text): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];
        $tokens = array_values(array_filter(
            $tokens,
            fn (string $token): bool => mb_strlen($token) > 3,
        ));

        return array_slice(array_values(array_unique($tokens)), 0, 5) ?: ['news'];
    }
}
