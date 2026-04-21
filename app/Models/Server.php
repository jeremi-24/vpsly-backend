<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Server extends Model
{
    protected $guarded = [];

    /**
     * Les champs masqués lors de la conversion en JSON.
     */
    protected $hidden = [
        'ssh_private_key',
    ];

    /**
     * Casts pour le chiffrement automatique.
     */
    protected $casts = [
        'ssh_private_key' => 'encrypted',
        'ssh_port' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}
