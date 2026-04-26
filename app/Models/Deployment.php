<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deployment extends Model
{
    protected $fillable = [
        'application_id',
        'branch',
        'deployment_uuid',
        'status',
        'logs',
        'commit',
        'commit_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'logs' => 'array',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * Logique de Coolify pour ajouter un log de manière atomique.
     */
    public function addLogEntry(string $message, string $type = 'info', bool $hidden = false)
    {
        $message = str($message)->trim();
        if ($message->isEmpty()) return;

        // Mappage de compatibilité (Supporte le nouveau style et le style Coolify legacy)
        $mappedType = match ($type) {
            'error', 'stderr' => 'error',
            'success' => 'success',
            default => 'info',
        };

        $newEntry = [
            'type' => $mappedType,
            'message' => $this->redactSensitiveInfo($message),
            'timestamp' => now()->toIso8601String(),
        ];

        // Transaction pour éviter les collisions de logs
        \Illuminate\Support\Facades\DB::transaction(function () use ($newEntry) {
            $this->refresh();
            
            $currentLogs = $this->logs ?? [];
            if (!is_array($currentLogs)) {
                $currentLogs = [];
            }
            
            $currentLogs[] = $newEntry;
            $this->logs = $currentLogs;

            $this->saveQuietly();
        });
    }

    /**
     * Anonymisation des données sensibles (Copy of Coolify).
     */
    private function redactSensitiveInfo(string $text): string
    {
        $app = $this->application;
        if (!$app) return $text;

        $lockedVars = $app->environmentVariables()
            ->pluck('value', 'key')
            ->filter();

        foreach ($lockedVars as $key => $value) {
            if (strlen($value) < 4) continue; // Évite de caviarder des petites chaînes
            $escapedValue = preg_quote($value, '/');
            $text = preg_replace('/' . $escapedValue . '/', '[REDACTED]', $text);
        }

        return $text;
    }
}

