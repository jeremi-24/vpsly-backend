<?php

namespace App\Models;

use App\Traits\HasTeam;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends BaseModel
{
    use HasTeam;
    protected $fillable = [
        'user_id',
        'team_id',
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
        'deployment_mode',
        'target_path',
        'deploy_script',
        'legacy_deployment_strategy',
        'nginx_configured',
        'log_command',
        'webhook_secret',
        'healthcheck_path',
        'healthcheck_status_codes',
        'ignore_healthcheck_warnings',
    ];

    protected function casts(): array
    {
        return [
            'is_deploying' => 'boolean',
            'last_deployed_at' => 'datetime',
            'has_laravel_scheduler' => 'boolean',
            'last_cron_synced_at' => 'datetime',
            'ignore_healthcheck_warnings' => 'boolean',
            'nginx_configured' => 'boolean',
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
        return $this->where($field ?? $this->getRouteKeyName(), $value)
            ->firstOrFail();
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) \Illuminate\Support\Str::uuid();
            }
            if (empty($model->webhook_secret)) {
                $model->webhook_secret = \Illuminate\Support\Str::random(32);
            }
        });

        static::updated(function ($model) {
            if ($model->isDirty(['domain', 'branch', 'build_pack', 'deployment_mode'])) {
                // On ne déclenche le déploiement que si l'application n'est pas déjà en train de déployer
                if (!$model->is_deploying) {
                    $deployment = $model->deployments()->create([
                        'status' => \App\Enums\DeploymentStatus::PENDING->value,
                        'type' => 'update',
                    ]);
                    
                    \App\Jobs\DeployApplicationJob::dispatch($deployment->id);
                }
            }
        });
    }
}
