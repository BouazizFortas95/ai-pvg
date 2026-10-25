<?php

namespace App\Models;

use App\Enums\VisualAssetType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VisualAsset extends Model
{
    protected $table = 'visual_assets';

    protected $fillable = [
        'type', 'storage_path', 'source_url', 'domain', 'license_type',
        'attribution_text', 'status', 'mime_type', 'byte_size',
        'width', 'height', 'checksum_sha256', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'type' => VisualAssetType::class,
            'metadata' => 'array',
            'byte_size' => 'integer',
        ];
    }

    public function sceneAssets(): HasMany
    {
        return $this->hasMany(SceneAsset::class);
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }
}
