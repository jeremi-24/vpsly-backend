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
        'domain',
        'status',
        'is_deploying',
        'last_deployed_at',
        'build_pack',
        'github_hook_id',
        'has_laravel_scheduler',
        'last_cron_synced_at',
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
        return $this->hasMany(StandaloneDatabase::class);
    }

    public function persistentVolumes(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }

    /**
     * Surcharge la résolution de la route pour forcer le filtrage par utilisateur connecté.
     * Protège toutes les routes utilisant l'Implicit Binding (Volumes, Crons, EnvVars, etc.)
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if (auth()->check()) {
            return $this->where('user_id', auth()->id())
                ->where($field ?? $this->getRouteKeyName(), $value)
                ->firstOrFail();
        }

        return parent::resolveRouteBinding($value, $field);
    }
}
