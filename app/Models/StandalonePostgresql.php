<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class StandalonePostgresql extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'description',
        'postgres_user',
        'postgres_password',
        'postgres_db',
        'postgres_initdb_args',
        'postgres_host_auth_method',
        'init_scripts',
        'status',
        'image',
        'is_public',
        'public_port',
        'ports_mappings',
        'limits_memory',
        'limits_memory_swap',
        'limits_memory_swappiness',
        'limits_memory_reservation',
        'limits_cpus',
        'limits_cpuset',
        'limits_cpu_shares',
        'started_at',
        'server_id',
        'application_id',
    ];

    protected $casts = [
        'init_scripts' => 'array',
        'postgres_password' => 'encrypted',
        'started_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::created(function ($database) {
            // Création automatique du volume de données comme dans Coolify
            $image = (string) ($database->image ?? '');
            $majorVersion = 15; // Défaut

            if (preg_match('/:(\d+)/i', $image, $matches)) {
                $majorVersion = (int) $matches[1];
            }

            $mountPath = $majorVersion >= 17
                ? '/var/lib/postgresql'
                : '/var/lib/postgresql/data';

            LocalPersistentVolume::create([
                'name' => 'postgres-data-' . $database->uuid,
                'mount_path' => $mountPath,
                'resource_id' => $database->id,
                'resource_type' => $database->getMorphClass(),
            ]);
        });
    }

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function persistentStorages()
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }

    // Accessors identiques à Coolify pour les URLs de connexion
    protected function internalDbUrl(): Attribute
    {
        return new Attribute(
            get: function () {
                $encodedUser = rawurlencode($this->postgres_user);
                $encodedPass = rawurlencode($this->postgres_password);
                // On utilise l'UUID comme hostname Docker
                return "postgres://{$encodedUser}:{$encodedPass}@{$this->uuid}:5432/{$this->postgres_db}";
            },
        );
    }

    protected function externalDbUrl(): Attribute
    {
        return new Attribute(
            get: function () {
                if ($this->is_public && $this->public_port) {
                    $serverIp = $this->server->ip;
                    $encodedUser = rawurlencode($this->postgres_user);
                    $encodedPass = rawurlencode($this->postgres_password);
                    return "postgres://{$encodedUser}:{$encodedPass}@{$serverIp}:{$this->public_port}/{$this->postgres_db}";
                }
                return null;
            }
        );
    }
}
