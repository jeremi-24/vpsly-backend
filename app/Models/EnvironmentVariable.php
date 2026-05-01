<?php
  
namespace App\Models;
  
use App\Traits\HasTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EnvironmentVariable extends Model
{
    use SoftDeletes, HasTeam;

    protected $fillable = [
        'application_id',
        'key',
        'value',
        'is_secret',
        'version',
        'updated_by',
    ];

    protected $casts = [
        'value' => 'encrypted',
        'is_secret' => 'boolean',
        'version' => 'integer',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
