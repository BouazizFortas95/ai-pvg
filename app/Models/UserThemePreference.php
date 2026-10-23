<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class UserThemePreference extends Model
{
    use HasFactory;
    protected $table = 'user_theme_preferences';
    protected $fillable = [
        'user_id', 'theme_name', 'primary_color', 'secondary_color',
        'accent_color', 'background_color', 'text_color', 'mode', 'border_radius',
    ];
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
