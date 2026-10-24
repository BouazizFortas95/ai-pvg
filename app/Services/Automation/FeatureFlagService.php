<?php

namespace App\Services\Automation;

use App\Models\FeatureFlag;

class FeatureFlagService
{
    public function enabled(string $key, bool $default = false): bool
    {
        $flag = FeatureFlag::query()->where('key', $key)->first();

        return $flag?->enabled ?? $default;
    }

    public function disabled(string $key): bool
    {
        return ! $this->enabled($key);
    }

    public function set(string $key, bool $enabled, string $scope = 'global', array $meta = []): FeatureFlag
    {
        return FeatureFlag::setFlag($key, $enabled, $scope, $meta);
    }

    /**
     * @return array<string, bool>
     */
    public function all(): array
    {
        return FeatureFlag::query()
            ->orderBy('key')
            ->pluck('enabled', 'key')
            ->map(fn (mixed $enabled): bool => (bool) $enabled)
            ->all();
    }
}
