<?php

namespace App\Services\Monitoring;

use App\Models\Server;
use App\Services\Deployment\SSHService;
use Illuminate\Support\Facades\Log;

class ServerMonitoringService
{
    public function __construct(protected SSHService $ssh) {}

    /**
     * Récupère les statistiques Docker de tous les conteneurs d'un serveur.
     */
    public function getDockerStats(Server $server): array
    {
        try {
            $this->ssh->connect($server);
            
            // On récupère les stats au format JSON pour un parsing facile
            $command = "docker stats --no-stream --format '{\"id\":\"{{.ID}}\",\"name\":\"{{.Name}}\",\"cpu\":\"{{.CPUPerc}}\",\"mem\":\"{{.MemUsage}}\",\"mem_perc\":\"{{.MemPerc}}\"}'";
            $output = $this->ssh->exec($command);
            
            $lines = explode("\n", trim($output));
            $stats = [];

            foreach ($lines as $line) {
                if (empty($line)) continue;
                $decoded = json_decode($line, true);
                if ($decoded) {
                    $stats[] = $decoded;
                }
            }

            return $stats;
        } catch (\Exception $e) {
            Log::error("[Monitoring] Failed to get stats for server {$server->ip}: " . $e->getMessage());
            return [];
        } finally {
            $this->ssh->disconnect();
        }
    }

    /**
     * Récupère la charge globale du VPS (CPU, RAM, Disk).
     */
    public function getSystemStats(Server $server): array
    {
        try {
            $this->ssh->connect($server);
            
            // RAM: free -m
            // CPU: top -bn1 | grep "Cpu(s)"
            // Disk: df -h / | tail -1
            $cmd = "free -m | awk 'NR==2{printf \"{\\\"mem_used\\\":%s,\\\"mem_total\\\":%s}\", $3, $2}' && " .
                   "echo '---' && " .
                   "top -bn1 | grep \"Cpu(s)\" | awk '{printf \"{\\\"cpu_usage\\\":%s}\", $2}' && " .
                   "echo '---' && " .
                   "df -h / | tail -1 | awk '{printf \"{\\\"disk_usage\\\":\\\"%s\\\"}\", $5}'";

            $output = $this->ssh->exec($cmd);
            $parts = explode('---', $output);

            return [
                'memory' => json_decode(trim($parts[0] ?? '{}'), true),
                'cpu' => json_decode(trim($parts[1] ?? '{}'), true),
                'disk' => json_decode(trim($parts[2] ?? '{}'), true),
            ];
        } catch (\Exception $e) {
            Log::error("[Monitoring] System stats failed: " . $e->getMessage());
            return [];
        } finally {
            $this->ssh->disconnect();
        }
    }
}
