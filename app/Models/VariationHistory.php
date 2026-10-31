<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VariationHistory extends Model
{
    use HasFactory;

    protected $table = 'variation_history';

    public $timestamps = false;

    protected $fillable = [
        'run_id',
        'seed',
        'variation_fingerprint',
        'categories_json',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'seed' => 'integer',
            'categories_json' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
