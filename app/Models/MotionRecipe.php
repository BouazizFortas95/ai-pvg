<?php

namespace App\Models;

use App\Enums\MotionCategory;
use App\Enums\MotionLicenseStatus;
use App\Enums\MotionRecipeStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MotionRecipe extends Model
{
    use HasFactory;

    protected $fillable = [
        'motion_source_id',
        'title',
        'prompt_text',
        'category',
        'source_payload',
        'status',
        'license_status',
    ];

    protected function casts(): array
    {
        return [
            'license_status' => MotionLicenseStatus::class,
            'status' => MotionRecipeStatus::class,
            'source_payload' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(MotionSource::class, 'motion_source_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(MotionComponent::class, 'recipe_id');
    }
}

