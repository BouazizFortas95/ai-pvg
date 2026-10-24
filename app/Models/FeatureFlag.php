<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeatureFlag extends Model
{
    protected $fillable = [
        'key',
        'enabled',
        'scope',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'meta' => 'array',
        ];
    }

    public static function isEnabled(string $key): bool
    {
        return (bool) static::query()->where('key', $key)->value('enabled');
    }

    public static function setFlag(string $key, bool $enabled, string $scope = 'global', array $meta = []): static
    {
        return static::query()->updateOrCreate(
            ['key' => $key],
            ['enabled' => $enabled, 'scope' => $scope, 'meta' => $meta],
        );
    }
}
