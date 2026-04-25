<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends Model
{
    protected $fillable = [
        'user_id',
        'server_id',
        'name',
        'repo_url',
        'branch',
        'github_hook_id',
    ];

    protected function casts(): array
    {
        return [
            'is_deploying' => 'boolean',
            'last_deployed_at' => 'datetime',
            'has_laravel_scheduler' => 'boolean',
            'last_cron_synced_at' => 'datetime',
        ];
    }

    public function scheduledTasks(): HasMany
    {
        return $this->hasMany(ScheduledTask::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function environmentVariables(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class);
    }

    public function databases(): HasMany
    {
        return $this->hasMany(StandalonePostgresql::class);
    }

    public function persistentVolumes(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }
}
