<?php

namespace App\Services\Publishing;

use App\Exceptions\Automation\PublishingDisabledException;
use App\Services\Automation\FeatureFlagService;
use Illuminate\Support\Facades\Schema;

/**
 * Phase H publishing is opt-in. Both the config switch and the database
 * feature flag must be enabled before any public-publish transition can be
 * evaluated.
 */
class PublishingGate
{
    public function __construct(private readonly FeatureFlagService $flags) {}

    public function enabled(): bool
    {
        if (! (bool) config('automation.publishing.enabled', false)) {
            return false;
        }

        if (! Schema::hasTable('feature_flags')) {
            return true;
        }

        return $this->flags->enabled($this->flagKey(), false);
    }

    public function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw PublishingDisabledException::disabled();
        }
    }

    public function flagKey(): string
    {
        $key = trim((string) config('automation.publishing.feature_flag', 'publishing.enabled'));

        return $key !== '' ? $key : 'publishing.enabled';
    }
}
