<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\Application;
use App\Services\MonitoringService;
use Illuminate\Http\JsonResponse;

class MonitoringController extends Controller
{
    public function __construct(
        protected MonitoringService $monitoring
    ) {}

    /**
     * Récupère les métriques d'un serveur.
     */
    public function serverStats(Server $server): JsonResponse
    {
        if ($server->user_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $stats = $this->monitoring->getServerStats($server);
        return response()->json($stats);
    }

    /**
     * Récupère les métriques d'une application ou d'une base de données.
     */
    public function containerStats(string $type, string $id): JsonResponse
    {
        $allowedTypes = ['applications', 'databases'];
        if (!in_array($type, $allowedTypes)) {
            return response()->json(['error' => 'Type invalide'], 400);
        }

        $resource = ($type === 'applications') 
            ? \App\Models\Application::findOrFail($id)
            : \App\Models\StandalonePostgresql::findOrFail($id);

        if ($resource->server->user_id !== auth()->id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $server = $resource->server;
        $stats = $this->monitoring->getServerStats($server);
        
        // On filtre pour ne garder que le container de la ressource
        $containerStats = collect($stats['containers'] ?? [])->firstWhere('name', "/{$resource->uuid}");
        
        return response()->json([
            'server_id' => $server->id,
            'system' => [
                'cpu_usage' => $stats['cpu_usage'] ?? 0,
                'mem_percent' => $stats['mem_percent'] ?? 0,
            ],
            'container' => $containerStats
        ]);
    }
}
