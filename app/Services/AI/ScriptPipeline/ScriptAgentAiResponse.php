<?php

namespace App\Services\AI\ScriptPipeline;

final readonly class ScriptAgentAiResponse
{
    public function __construct(
        public string $content,
        public string $provider,
        public string $model,
        public int $tokensIn,
        public int $tokensOut,
        public int $costMicros,
    ) {}
}
