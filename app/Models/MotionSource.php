<?php

namespace App\Models;

use App\Enums\MotionLicenseStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MotionSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_url',
        'creator_handle',
        'site_name',
        'license_status',
        'attribution_text',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'license_status' => MotionLicenseStatus::class,
        ];
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(MotionRecipe::class);
    }
}

