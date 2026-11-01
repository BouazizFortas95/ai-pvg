<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoiceProfile extends Model
{
    protected $fillable = [
        'provider',
        'voice_id',
        'name',
        'model_id',
        'language',
        'settings',
        'license_note',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }
}

