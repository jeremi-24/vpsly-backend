<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupSetting extends Model
{
    protected $fillable = [
        'user_id',
        'frequency',
        'execution_time',
        'storage_destination',
        'storage_credentials',
        'notification_channel',
        'notification_phone',
        'notification_email',
        'active',
    ];

    protected $casts = [
        'storage_credentials' => 'encrypted:json', // Chiffre les secrets et gère le format JSON
        'active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
