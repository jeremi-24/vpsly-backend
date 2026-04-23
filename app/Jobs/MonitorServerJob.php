<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Monitoring\ServerMonitoringService;
use App\Events\ServerMetricsEvent;
use Illuminate\Bus\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class MonitorServerJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public function __construct(protected Server $server) {}

    public function handle(ServerMonitoringService $monitoring)
    {
        $dockerStats = $monitoring->getDockerStats($this->server);
        $systemStats = $monitoring->getSystemStats($this->server);

        event(new ServerMetricsEvent($this->server->id, [
            'docker' => $dockerStats,
            'system' => $systemStats,
            'timestamp' => now()->toIso8601String()
        ]));
    }
}
