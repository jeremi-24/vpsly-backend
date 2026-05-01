<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class StandaloneDatabase extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $table = 'standalone_databases';

    protected $fillable = [
        'uuid',
        'type',
        'name',
        'description',
        'db_user',
        'db_password',
        'db_name',
        'postgres_initdb_args',
        'postgres_host_auth_method',
        'init_scripts',
        'status',
        'has_adminer',
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

    protected $appends = [
        'adminer_url',
        'internal_db_url',
        'external_db_url',
    ];

    protected $casts = [
        'init_scripts' => 'array',
        'db_password' => 'encrypted',
        'started_at' => 'datetime',
        'has_adminer' => 'boolean',
    ];

    protected static function booted()
    {
        static::created(function ($database) {
            // Création automatique du volume de données adapté à la stack
            $mountPath = match ($database->type) {
                'mysql', 'mariadb' => '/var/lib/mysql',
                'redis' => '/data',
                default => '/var/lib/postgresql/data',
            };

            LocalPersistentVolume::create([
                'name' => 'db-data-' . $database->uuid,
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

    // Accessors génériques pour les URLs de connexion
    protected function internalDbUrl(): Attribute
    {
        return new Attribute(
            get: function () {
                $user = rawurlencode((string) $this->db_user);
                $pass = rawurlencode((string) $this->db_password);
                $host = $this->uuid;
                $name = (string) $this->db_name;

                return match ($this->type) {
                    'mysql', 'mariadb' => "mysql://{$user}:{$pass}@{$host}:3306/{$name}",
                    'redis' => "redis://:{$pass}@{$host}:6379",
                    default => "postgres://{$user}:{$pass}@{$host}:5432/{$name}",
                };
            },
        );
    }

    protected function externalDbUrl(): Attribute
    {
        return new Attribute(
            get: function () {
                if ($this->is_public && $this->public_port) {
                    $serverIp = $this->server->ip;
                    $user = rawurlencode((string) $this->db_user);
                    $pass = rawurlencode((string) $this->db_password);
                    $name = (string) $this->db_name;

                    return match ($this->type) {
                        'mysql', 'mariadb' => "mysql://{$user}:{$pass}@{$serverIp}:{$this->public_port}/{$name}",
                        'redis' => "redis://:{$pass}@{$serverIp}:{$this->public_port}",
                        default => "postgres://{$user}:{$pass}@{$serverIp}:{$this->public_port}/{$name}",
                    };
                }
                return null;
            }
        );
    }

    /**
     * URL pour accéder à l'interface Adminer si activée.
     */
    protected function adminerUrl(): Attribute
    {
        return new Attribute(
            get: function () {
                if ($this->has_adminer) {
                    $serverIp = $this->server->ip;
                    return "adminer-{$this->uuid}.{$serverIp}.sslip.io";
                }
                return null;
            }
        );
    }
}
