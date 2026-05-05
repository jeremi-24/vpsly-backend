<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

$waitingJobs = DB::table('jobs')->count();
echo "Jobs en attente : " . $waitingJobs . "\n";

$lastLogs = DB::table('deployment_logs')->orderBy('id', 'desc')->limit(5)->get();
echo "Derniers logs enregistrés :\n";
foreach ($lastLogs as $log) {
    echo "[" . $log->created_at . "] App ID: " . $log->application_id . " | Content: " . substr($log->content, 0, 50) . "...\n";
}

$runningJobs = DB::table('jobs')->whereNotNull('reserved_at')->count();
echo "Jobs actuellement en cours d'exécution : " . $runningJobs . "\n";
