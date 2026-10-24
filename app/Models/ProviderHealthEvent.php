<?php

namespace App\Models;

use App\Enums\ProviderHealthEventType;
use Illuminate\Database\Eloquent\Model;

class ProviderHealthEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'provider',
        'event_type',
        'http_status',
        'retry_after_seconds',
        'message_sanitized',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => ProviderHealthEventType::class,
            'http_status' => 'integer',
            'retry_after_seconds' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
