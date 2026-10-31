<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AvatarCharacter extends Model
{
    use HasFactory;

    protected $table = 'avatar_characters';

    protected $fillable = [
        'character_id',
        'spec_json',
    ];

    protected function casts(): array
    {
        return [
            'spec_json' => 'array',
        ];
    }
}
