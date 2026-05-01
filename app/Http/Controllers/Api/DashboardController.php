<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\StandaloneDatabase;
use App\Models\Server;
use App\Models\Deployment;
use App\Services\MonitoringService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __construct(
        protected MonitoringService $monitoring
    ) {}

    public function index(): JsonResponse
    {
        $user = auth()->user();
        
        $appsCount = Application::whereHas('server', function($q) use ($user) {
            $q->where('user_id', $user->id);
        })->count();

        $dbCount = StandaloneDatabase::whereHas('server', function($q) use ($user) {
            $q->where('user_id', $user->id);
        })->count();

        $deploymentsToday = Deployment::whereHas('application.server', function($q) use ($user) {
            $q->where('user_id', $user->id);
        })->whereDate('created_at', now()->toDateString())->count();

        // Stats Globales (Moyenne sur tous les serveurs de l'user)
        $servers = Server::where('user_id', $user->id)->get();
        $totalCpu = 0;
        $totalMem = 0;
        $serversWithStats = 0;

        foreach ($servers as $server) {
            $stats = $this->monitoring->getServerStats($server);
            if (!isset($stats['error'])) {
                $totalCpu += $stats['cpu_usage'] ?? 0;
                $totalMem += $stats['mem_percent'] ?? 0;
                $serversWithStats++;
            }
        }

        // Derniers déploiements
        $recentDeployments = Deployment::with('application')
            ->whereHas('application.server', function($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->latest()
            ->limit(5)
            ->get()
            ->map(function($d) {
                return [
                    'id' => $d->id,
                    'application_id' => $d->application_id,
                    'app_name' => $d->application->name,
                    'branch' => $d->application->branch,
                    'status' => $d->status,
                    'created_at' => $d->created_at->toDateTimeString(),
                    'time_ago' => $d->created_at->diffForHumans(),
                ];
            });

        // Données pour le graphique (déploiements par mois)
        $isSqlite = config('database.default') === 'sqlite';
        $monthFunc = $isSqlite ? "strftime('%m', created_at)" : "MONTH(created_at)";

        $overviewData = Deployment::whereHas('application.server', function($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->selectRaw("{$monthFunc} as month, COUNT(*) as count")
            ->whereYear('created_at', now()->year)
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(function($d) {
                $monthNum = (int)$d->month;
                return [
                    'name' => date('M', mktime(0, 0, 0, $monthNum, 10)),
                    'total' => $d->count,
                ];
            });

        // Top 3 Serveurs avec stats détaillées
        $topServers = $servers->take(3)->map(function($server) {
            $stats = $this->monitoring->getServerStats($server);
            return [
                'id' => $server->id,
                'name' => $server->name,
                'ip' => $server->ip,
                'status' => $server->status,
                'cpu_usage' => $stats['cpu_usage'] ?? 0,
                'mem_percent' => $stats['mem_percent'] ?? 0,
                'disk_percent' => $stats['disk_percent'] ?? 0,
            ];
        });

        return response()->json([
            'stats' => [
                'applications' => $appsCount,
                'databases' => $dbCount,
                'deployments_today' => $deploymentsToday,
                'avg_cpu' => $serversWithStats > 0 ? round($totalCpu / $serversWithStats, 1) : 0,
                'avg_mem' => $serversWithStats > 0 ? round($totalMem / $serversWithStats, 1) : 0,
            ],
            'servers_count' => $servers->count(),
            'top_servers' => $topServers,
            'recent_deployments' => $recentDeployments,
            'overview_data' => $overviewData,
        ]);
    }
}
