<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Deployment extends Model
{
    protected $fillable = [
        'application_id',
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
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    /**
     * Logique de Coolify pour ajouter un log de manière atomique.
     */
    public function addLogEntry(string $message, string $type = 'stdout', bool $hidden = false)
    {
        if ($type === 'error') {
            $type = 'stderr';
        }
        
        $message = str($message)->trim();
        
        $newLogEntry = [
            'command' => null,
            'output' => $this->redactSensitiveInfo($message),
            'type' => $type,
            'timestamp' => \Illuminate\Support\Carbon::now('UTC'),
            'hidden' => $hidden,
            'batch' => 1,
        ];

        // Transaction pour éviter les collisions de logs (Copy of Coolify)
        \Illuminate\Support\Facades\DB::transaction(function () use ($newLogEntry) {
            $this->refresh();

            if ($this->logs) {
                $previousLogs = json_decode($this->logs, true) ?? [];
                $newLogEntry['order'] = count($previousLogs) + 1;
                $previousLogs[] = $newLogEntry;
                $this->logs = json_encode($previousLogs);
            } else {
                $this->logs = json_encode([$newLogEntry]);
            }

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

