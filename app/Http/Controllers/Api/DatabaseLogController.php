<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StandalonePostgresql;
use App\Services\Deployment\RuntimeLogService;
use App\Services\Deployment\SSHService;
use App\Jobs\StreamDatabaseLogsJob;
use Illuminate\Http\Request;

class DatabaseLogController extends Controller
{
    /**
     * Récupère l'historique récent des logs de la base de données.
     */
    public function index(StandalonePostgresql $database, RuntimeLogService $logService, SSHService $ssh)
    {
        if (!$database->server) {
            return response()->json(['error' => 'Server not found'], 404);
        }

        // 1. Connexion SSH au serveur
        $ssh->connect($database->server);

        // 2. Récupération des logs
        $logs = $logService->getLastLogs($database);

        $ssh->disconnect();

        return response()->json([
            'logs' => $logs
        ]);
    }

    /**
     * Déclenche le streaming vers les WebSockets pour la base de données.
     */
    public function stream(StandalonePostgresql $database)
    {
        // On dispatch le job sur une queue dédiée 'logs' pour ne pas bloquer les déploiements
        StreamDatabaseLogsJob::dispatch($database->id)->onQueue('logs');

        return response()->json([
            'message' => 'Database log streaming started'
        ]);
    }
}
