<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Backup extends Model
{
    protected $fillable = [
        'application_id',
        'database_id',
        'name',
        'type',
        'status',
        'size',
        'path',
        'notes',
    ];

    public function application(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function database(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(StandaloneDatabase::class, 'database_id');
    }
}
