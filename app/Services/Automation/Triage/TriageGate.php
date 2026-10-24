<?php

namespace App\Services\Automation\Triage;

use App\Exceptions\Automation\TriageDisabledException;
use App\Services\Automation\FeatureFlagService;
use Illuminate\Support\Facades\Schema;

class TriageGate
{
    public function __construct(private readonly FeatureFlagService $flags) {}

    public function enabled(): bool
    {
        $default = (bool) config('automation.triage.enabled', false);
        $key = $this->flagKey();

        if (! Schema::hasTable('feature_flags')) {
            return $default;
        }

        return $this->flags->enabled($key, $default);
    }

    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw TriageDisabledException::disabled();
        }
    }

    public function flagKey(): string
    {
        $key = trim((string) config('automation.triage.feature_flag', 'triage.news_scoring_enabled'));

        return $key !== '' ? $key : 'triage.news_scoring_enabled';
    }
}
