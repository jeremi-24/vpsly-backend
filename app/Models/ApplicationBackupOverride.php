<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationBackupOverride extends Model
{
    protected $fillable = [
        'application_id',
        'is_enabled',
        'frequency_override',
        'excluded_paths',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'excluded_paths' => 'json',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
