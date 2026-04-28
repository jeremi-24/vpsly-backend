<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\Api\EnvironmentVariableController;
use App\Http\Controllers\Api\ApplicationLogController;
use App\Http\Controllers\Api\DatabaseController;
use Illuminate\Support\Facades\Broadcast;

// Si vous utilisez Sanctum avec Auth : Route::middleware('auth:sanctum')->get('/user', function () { ... });

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
    Route::put('/user', [\App\Http\Controllers\Api\UserController::class, 'update']);

    // Route d'authentification Broadcast personnalisée (pour contourner les problèmes de middleware par défaut)
    Route::post('/broadcasting/auth', function (Request $request) {
        return Broadcast::auth($request);
    });

    Route::post('/logout', function (Request $request) {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    });

    Route::get('/dashboard', [\App\Http\Controllers\Api\DashboardController::class, 'index']);

    // Gestion des Serveurs
    Route::prefix('servers')->group(function () {
        Route::get('/', [ServerController::class, 'index']);
        Route::post('/', [ServerController::class, 'store']);
        Route::get('/{server}', [ServerController::class, 'show']);
        Route::put('/{server}', [ServerController::class, 'update']);
        Route::delete('/{server}', [ServerController::class, 'destroy']);
        Route::post('/{server}/test-connection', [ServerController::class, 'testConnection']);
        Route::post('/{server}/prune', [ServerController::class, 'prune']);
    });

    // Gestion des Applications
    Route::prefix('applications')->group(function () {
        // ... (routes applications)
        Route::get('/', [ApplicationController::class, 'index']);
        Route::post('/', [ApplicationController::class, 'store']);
        Route::get('/{id}', [ApplicationController::class, 'show']);
        Route::get('/{id}/deployments', [ApplicationController::class, 'deployments']);
        Route::delete('/{id}', [ApplicationController::class, 'destroy']);

        // Variables d'Environnement
        Route::prefix('{application}/env-vars')->group(function () {
            Route::get('/', [EnvironmentVariableController::class, 'index']);
            Route::post('/', [EnvironmentVariableController::class, 'store']);
            Route::post('/bulk', [EnvironmentVariableController::class, 'bulk']);
            Route::delete('/{id}', [EnvironmentVariableController::class, 'destroy']);
            Route::get('/{id}/reveal', [EnvironmentVariableController::class, 'reveal']);
        });

        // Logs de Runtime
        Route::prefix('{app}/logs')->group(function () {
            Route::get('/', [ApplicationLogController::class, 'index']);
            Route::post('/stream', [ApplicationLogController::class, 'stream']);
        });

        // Volumes Persistants
        Route::prefix('{application}/volumes')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\ApplicationVolumeController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\ApplicationVolumeController::class, 'store']);
            Route::delete('/{id}', [\App\Http\Controllers\Api\ApplicationVolumeController::class, 'destroy']);
        });

        // Crons (Scheduled Tasks)
        Route::prefix('{application}/crons')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\ApplicationCronController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\ApplicationCronController::class, 'store']);
            Route::put('/toggle-laravel', [\App\Http\Controllers\Api\ApplicationCronController::class, 'toggleLaravel']);
            Route::delete('/{id}', [\App\Http\Controllers\Api\ApplicationCronController::class, 'destroy']);
            Route::post('/sync', [\App\Http\Controllers\Api\ApplicationCronController::class, 'sync']);
        });

        // Backups
        Route::prefix('{application}/backups')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\ApplicationBackupController::class, 'index']);
            Route::post('/', [\App\Http\Controllers\Api\ApplicationBackupController::class, 'store']);
            Route::get('/{id}/download', [\App\Http\Controllers\Api\ApplicationBackupController::class, 'download']);
            Route::delete('/{id}', [\App\Http\Controllers\Api\ApplicationBackupController::class, 'destroy']);
        });
    });


    // Déploiements
    Route::prefix('deployments')->group(function () {
        Route::post('/', [DeploymentController::class, 'store']);
        Route::get('/{id}/status', [DeploymentController::class, 'status']);
        Route::get('/{id}/logs', [DeploymentController::class, 'logs']);
    });

    // Intégration GitHub
    Route::prefix('github')->group(function () {
        Route::get('/auth/redirect', [\App\Http\Controllers\Api\GitHubApiController::class, 'redirect']);
        Route::get('/user', [\App\Http\Controllers\Api\GitHubApiController::class, 'user']);
        Route::get('/repositories', [\App\Http\Controllers\Api\GitHubApiController::class, 'repositories']);
        Route::get('/branches', [\App\Http\Controllers\Api\GitHubApiController::class, 'branches']);
    });

    // Gestion des Bases de données
    Route::prefix('databases')->group(function () {
        Route::get('/', [DatabaseController::class, 'index']);
        Route::post('/', [DatabaseController::class, 'store']);
        Route::get('/{database}', [DatabaseController::class, 'show']);
        Route::delete('/{database}', [DatabaseController::class, 'destroy']);
        Route::post('/{database}/deploy', [DatabaseController::class, 'deploy']);
        Route::post('/{database}/verify', [DatabaseController::class, 'verifyIntegrity']);
        Route::patch('/{database}/toggle-public', [DatabaseController::class, 'togglePublic']);
        Route::post('/{database}/link', [DatabaseController::class, 'link']);
        Route::post('/{database}/unlink', [DatabaseController::class, 'unlink']);

        // Logs de Runtime des Bases de données
        Route::prefix('{database}/logs')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\DatabaseLogController::class, 'index']);
            Route::post('/stream', [\App\Http\Controllers\Api\DatabaseLogController::class, 'stream']);
        });
    });

    // Backups Globaux
    Route::get('/backups', [\App\Http\Controllers\Api\BackupController::class, 'index']);

    // Monitoring
    Route::get('/servers/{server}/metrics', [\App\Http\Controllers\Api\MonitoringController::class, 'serverStats']);
    Route::get('/{type}/{id}/metrics', [\App\Http\Controllers\Api\MonitoringController::class, 'containerStats'])
        ->where('type', 'applications|databases');
});

Route::get('/github/auth/callback', [\App\Http\Controllers\Api\GitHubApiController::class, 'callback']);

Route::post('/webhooks/github', [\App\Http\Controllers\Api\GitHubWebhookController::class, 'handle']);
