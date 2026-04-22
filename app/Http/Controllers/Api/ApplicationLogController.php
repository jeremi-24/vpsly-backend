<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\Application;
use App\Services\Deployment\RuntimeLogService;
use App\Services\Deployment\SSHService;
use App\Jobs\StreamRuntimeLogsJob;

class ApplicationLogController extends Controller
{
    /**
     * Récupère l'historique récent des logs.
     */
    public function index(Application $app, RuntimeLogService $logService, SSHService $ssh)
    {
        // 1. Connexion SSH au serveur de l'app
        $ssh->connect($app->server);

        // 2. Récupération des 100 dernières lignes
        $logs = $logService->getLastLogs($app);

        $ssh->disconnect();

        return response()->json([
            'logs' => $logs
        ]);
    }

    /**
     * Déclenche le streaming vers les WebSockets.
     */
    public function stream(Application $app)
    {
        // On dispatch le job en queue (asynchrone)
        StreamRuntimeLogsJob::dispatch($app->id);

        return response()->json([
            'message' => 'Log streaming started'
        ]);
    }
}
