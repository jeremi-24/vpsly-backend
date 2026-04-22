<?php

namespace App\Services\Deployment;

use App\Enums\LogType;
use App\Models\Deployment;
use App\Models\DeploymentLog;
use App\Events\DeploymentLogEvent;

class LogStreamer
{
    protected array $buffer = [];
    protected int $batchSize = 50;
    protected float $lastFlushTime;
    protected float $flushInterval = 0.2; // 200ms Sweet Spot

    public function __construct()
    {
        $this->lastFlushTime = microtime(true);
    }

    /**
     * Enregistre un log et déclenche un flush si nécessaire.
     */
    public function log(Deployment $deployment, string $line, LogType $type = LogType::INFO): void
    {
        // 1. Audit (DB)
        DeploymentLog::create([
            'deployment_id' => $deployment->id,
            'line' => $line,
            'type' => $type->value,
        ]);

        // 2. Buffer pour le live (WebSocket)
        $this->buffer[] = [
            'type' => $type->value,
            'message' => $line,
            'timestamp' => now()->toISOString()
        ];

        // 3. Flush intelligent (Batching)
        // Flush si : N lignes atteintes OU intervalle de temps dépassé
        if (count($this->buffer) >= $this->batchSize || (microtime(true) - $this->lastFlushTime) >= $this->flushInterval) {
            $this->flush($deployment);
        }
    }

    /**
     * Envoie le buffer via WebSocket et réinitialise le timer.
     */
    public function flush(Deployment $deployment): void
    {
        if (empty($this->buffer)) {
            return;
        }

        // Broadcast groupé pour fluidifier l'UI (Reverb)
        broadcast(new DeploymentLogEvent($deployment->id, $this->buffer));

        $this->buffer = [];
        $this->lastFlushTime = microtime(true);
    }
}
