<?php

namespace App\Services\Automation\Stages;

use App\Models\News;
use App\Models\StoryCluster;
use App\Services\AI\ScriptPipeline\ScriptSource;
use App\Services\AI\ScriptPipeline\ScriptSourceFactory;
use App\Services\Automation\AutomationContext;

trait ResolvesScriptSource
{
    private function resolveScriptSource(AutomationContext $context): ?ScriptSource
    {
        $subject = $context->run->subject;
        $target = isset($context->payload['target_duration']) && is_numeric($context->payload['target_duration'])
            ? (int) $context->payload['target_duration']
            : null;
        $language = isset($context->payload['language']) && is_string($context->payload['language'])
            ? $context->payload['language']
            : null;

        if ($subject instanceof StoryCluster || $subject instanceof News) {
            return app(ScriptSourceFactory::class)->fromModel($subject, $target, $language);
        }

        $clusterId = $context->payload['story_cluster_id'] ?? $context->payload['cluster_id'] ?? null;

        if (is_numeric($clusterId)) {
            $cluster = StoryCluster::query()->find((int) $clusterId);

            return $cluster !== null
                ? app(ScriptSourceFactory::class)->fromModel($cluster, $target, $language)
                : null;
        }

        $newsId = $context->payload['news_id'] ?? null;

        if (is_numeric($newsId)) {
            $news = News::query()->find((int) $newsId);

            return $news !== null
                ? app(ScriptSourceFactory::class)->fromModel($news, $target, $language)
                : null;
        }

        return null;
    }
}
