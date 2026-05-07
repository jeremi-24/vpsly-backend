<?php

namespace App\Services\Deployment;

use App\Enums\LogType;
use App\Models\Deployment;
use App\Models\DeploymentLog;
use App\Events\DeploymentLogEvent;

class LogStreamer
{
    protected array $buffer = [];
    protected int $batchSize = 2; // Réduit pour le Streaming v2 (quasi ligne par ligne)
    protected float $lastFlushTime;
    protected float $flushInterval = 0.05; // 50ms pour une fluidité extrême

    public function __construct()
    {
        $this->lastFlushTime = microtime(true);
    }

    /**
     * Enregistre un log (Méthode Coolify) et déclenche un flush si nécessaire.
     */
    public function log(Deployment $deployment, string $line, LogType $type = LogType::INFO): void
    {
        // 1. Audit (Format Coolify : JSON dans la table deployments)
        $coolifyType = ($type === LogType::ERROR) ? 'stderr' : 'stdout';
        $deployment->addLogEntry($line, $coolifyType);

        // 2. Buffer pour le live (WebSocket via Reverb)
        $this->buffer[] = [
            'type' => $type->value,
            'message' => $line,
            'timestamp' => now()->toISOString()
        ];

        // 3. Flush intelligent (Streaming v2)
        // On flush si on a 2 lignes OU si 50ms se sont écoulées
        if (count($this->buffer) >= $this->batchSize || (microtime(true) - $this->lastFlushTime) >= $this->flushInterval) {
            $this->flush($deployment);
        }
    }

    /**
     * Envoie le buffer via WebSocket (Reverb) et réinitialise le timer.
     */
    public function flush(Deployment $deployment): void
    {
        if (empty($this->buffer)) {
            return;
        }

        // Broadcast immédiat (ShouldBroadcastNow)
        try {
            broadcast(new DeploymentLogEvent($deployment->id, $this->buffer));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("[LogStreamer] Broadcast failed: " . $e->getMessage());
        }

        $this->buffer = [];
        $this->lastFlushTime = microtime(true);
    }
}

