<?php

namespace App\Models;

use App\Enums\MotionLicenseStatus;
use App\Enums\MotionComponentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MotionComponent extends Model
{
    use HasFactory;

    protected $fillable = [
        'recipe_id',
        'component_key',
        'version',
        'category',
        'html_template',
        'gsap_script',
        'params_schema',
        'supported_formats',
        'sfx_hooks',
        'tone_compatibility',
        'contrast_rating',
        'license_status',
        'status',
        'approved_at',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'params_schema' => 'array',
            'supported_formats' => 'array',
            'sfx_hooks' => 'array',
            'tone_compatibility' => 'array',
            'contrast_rating' => 'float',
            'license_status' => MotionLicenseStatus::class,
            'status' => MotionComponentStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(MotionRecipe::class, 'recipe_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(MotionComponentUsage::class);
    }

    /**
     * Component is renderable ONLY if it has been human-approved AND is cleared or original.
     * Reference-only components can never be rendered in real production videos.
     */
    public function isRenderable(): bool
    {
        return $this->status === MotionComponentStatus::Approved
            && $this->license_status instanceof MotionLicenseStatus
            && $this->license_status->canBeRendered();
    }
}

