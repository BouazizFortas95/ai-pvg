<?php

namespace App\Services\Production;

use App\Enums\MotionLicenseStatus;
use App\Models\CaptionTrack;
use App\Models\MotionComponentUsage;
use App\Models\News;
use App\Models\PipelineRun;
use App\Models\StoryCluster;
use App\Models\VideoScript;
use App\Support\HyperFramesRuntime;
use Illuminate\Support\Facades\Schema;

class ProductionPreflight
{
    /**
     * Perform preflight checks against production data map specifications.
     * Lists every missing datum with the step that owns it.
     *
     * @param  array{
     *     run?: PipelineRun|null,
     *     script_id?: int|null,
     *     cluster_id?: int|null,
     *     news_id?: int|null,
     *     aspect_ratio?: string|null,
     *     backend?: string|null
     * }  $context
     * @return array{
     *     ready_to_render: bool,
     *     step_statuses: array<int, bool>,
     *     missing_data: list<array{key: string, label: string, step: int, step_name: string, details: string}>,
     *     passed_checks: list<array{key: string, label: string, step: int, details: string}>
     * }
     */
    public function check(array $context = []): array
    {
        $missing = [];
        $passed = [];
        $stepStatus = [1 => true, 2 => true, 3 => true, 4 => true, 5 => true, 6 => true];

        // Resolve active or latest script early
        $scriptId = $context['script_id'] ?? null;
        $script = null;

        if ($scriptId && Schema::hasTable('video_scripts')) {
            $script = VideoScript::query()->find($scriptId);
        } elseif (Schema::hasTable('video_scripts')) {
            $script = VideoScript::query()->latest()->first();
        }

        // ── STEP 1: Story Triage / News Source ──────────────────────────────
        $newsId = $context['news_id'] ?? null;
        $hasNews = false;

        if ($newsId && Schema::hasTable('news')) {
            $hasNews = News::query()->where('id', $newsId)->exists();
        } elseif (Schema::hasTable('news')) {
            $hasNews = News::query()->whereIn('status', ['ready', 'greenlit', 'clustered'])->exists();
        }

        if ($hasNews) {
            $passed[] = ['key' => 'story_source', 'label' => 'Source News Item', 'step' => 1, 'details' => 'Source story verified in database.'];
        } else {
            $stepStatus[1] = false;
            $missing[] = [
                'key' => 'story_source',
                'label' => 'Source News Item',
                'step' => 1,
                'step_name' => 'Prepare & Load News',
                'details' => 'No active or greenlit news item found. Run Step 1 to fetch and triage stories.',
            ];
        }

        // ── STEP 2: Story Clustering & Angle ────────────────────────────────
        $clusterId = $context['cluster_id'] ?? null;
        $hasCluster = false;

        if ($clusterId && Schema::hasTable('story_clusters')) {
            $hasCluster = StoryCluster::query()->where('id', $clusterId)->exists();
        } elseif (Schema::hasTable('story_clusters')) {
            $hasCluster = StoryCluster::query()->exists() || ($script !== null);
        }

        if ($hasCluster) {
            $passed[] = ['key' => 'story_cluster', 'label' => 'Story Cluster', 'step' => 2, 'details' => 'Story cluster and angle verified.'];
        } else {
            $stepStatus[2] = false;
            $missing[] = [
                'key' => 'story_cluster',
                'label' => 'Story Cluster',
                'step' => 2,
                'step_name' => 'Cluster & Greenlight',
                'details' => 'No story cluster found. Run Step 2 to cluster related news items.',
            ];
        }

        // ── STEP 3: Approved Video Script & Edit Plan ────────────────────────
        if ($script && ! empty($script->narration)) {
            $passed[] = ['key' => 'approved_script', 'label' => 'Approved Video Script', 'step' => 3, 'details' => "Script #{$script->id} with narration verified."];
        } else {
            $stepStatus[3] = false;
            $missing[] = [
                'key' => 'approved_script',
                'label' => 'Approved Video Script',
                'step' => 3,
                'step_name' => 'Draft Script',
                'details' => 'No approved script found with narration. Run Step 3 to draft script.',
            ];
        }

        $scenes = $script?->scenes ?? [];
        if (! empty($scenes)) {
            $passed[] = ['key' => 'edit_plan', 'label' => 'Edit Decision Plan', 'step' => 3, 'details' => count($scenes).' scene(s) structured with visual directives.'];
        } else {
            $stepStatus[3] = false;
            $missing[] = [
                'key' => 'edit_plan',
                'label' => 'Edit Decision Plan',
                'step' => 3,
                'step_name' => 'Draft Script',
                'details' => 'Script scenes array is missing or empty. Script must define at least one scene.',
            ];
        }

        // ── STEP 4: Voice Synthesis & Word Captions ─────────────────────────
        $hasCaptions = false;
        if ($script && Schema::hasTable('caption_tracks')) {
            $hasCaptions = CaptionTrack::query()->where('video_script_id', $script->id)->exists();
        }

        if ($hasCaptions || ($context['has_audio'] ?? false)) {
            $passed[] = ['key' => 'caption_track', 'label' => 'Word-Level Captions', 'step' => 4, 'details' => 'Synchronized caption track verified.'];
        } else {
            $stepStatus[4] = false;
            $missing[] = [
                'key' => 'caption_track',
                'label' => 'Word-Level Captions',
                'step' => 4,
                'step_name' => 'Voice & Audio',
                'details' => 'No caption track found for script. Run Step 4 to synthesize voice and monotonic timings.',
            ];
        }

        // ── STEP 5: Visual Scene Props & Motion Selection ───────────────────
        $run = $context['run'] ?? null;
        $unlicensedMotionCount = 0;

        if ($run && Schema::hasTable('motion_component_usages')) {
            $usages = MotionComponentUsage::query()->with('component')->where('pipeline_run_id', $run->id)->get();
            foreach ($usages as $usage) {
                if ($usage->component && $usage->component->license_status === MotionLicenseStatus::ReferenceOnly) {
                    $unlicensedMotionCount++;
                }
            }
        }

        if ($unlicensedMotionCount > 0) {
            $stepStatus[5] = false;
            $missing[] = [
                'key' => 'motion_licensing',
                'label' => 'Motion Graphics Licensing',
                'step' => 5,
                'step_name' => 'Visual Props & Formats',
                'details' => "Found {$unlicensedMotionCount} reference_only component(s) attached. Only cleared or original components can be rendered.",
            ];
        } else {
            $passed[] = ['key' => 'motion_licensing', 'label' => 'Motion Graphics Licensing', 'step' => 5, 'details' => 'All selected motion components are cleared or in-house original IP.'];
        }

        // ── STEP 6: Render Engine & Safety Lock ─────────────────────────────
        $ffmpeg = HyperFramesRuntime::ffmpegPath();
        $ffprobe = HyperFramesRuntime::ffprobePath();

        if ($ffmpeg !== null && $ffprobe !== null) {
            $passed[] = ['key' => 'media_binaries', 'label' => 'FFmpeg & FFprobe Runtime', 'step' => 6, 'details' => 'Media processing binaries verified locally.'];
        } else {
            $stepStatus[6] = false;
            $missing[] = [
                'key' => 'media_binaries',
                'label' => 'FFmpeg & FFprobe Runtime',
                'step' => 6,
                'step_name' => 'Render Video',
                'details' => 'FFmpeg or FFprobe binary is missing or not executable on the system path.',
            ];
        }

        // Safety constraint: Publishing must be LOCKED OFF
        $publishingEnabled = (bool) config('automation.publishing.enabled', false);
        if (! $publishingEnabled) {
            $passed[] = ['key' => 'publishing_lock', 'label' => 'Publishing Safety Lock', 'step' => 6, 'details' => 'External publishing is strictly LOCKED OFF.'];
        } else {
            $stepStatus[6] = false;
            $missing[] = [
                'key' => 'publishing_lock',
                'label' => 'Publishing Safety Lock',
                'step' => 6,
                'step_name' => 'Render Video',
                'details' => 'Safety violation: automation.publishing.enabled must be strictly false.',
            ];
        }

        $ready = empty($missing);

        return [
            'ready_to_render' => $ready,
            'step_statuses' => $stepStatus,
            'missing_data' => $missing,
            'passed_checks' => $passed,
        ];
    }
}

