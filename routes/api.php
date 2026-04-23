<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\Api\EnvironmentVariableController;
use App\Http\Controllers\Api\ApplicationLogController;
use App\Http\Controllers\Api\DatabaseController;

// Si vous utilisez Sanctum avec Auth : Route::middleware('auth:sanctum')->get('/user', function () { ... });

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::post('/logout', function (Request $request) {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    });

    // Gestion des Serveurs
    Route::prefix('servers')->group(function () {
        Route::get('/', [ServerController::class, 'index']);
        Route::post('/', [ServerController::class, 'store']);
        Route::get('/{server}', [ServerController::class, 'show']);
        Route::put('/{server}', [ServerController::class, 'update']);
        Route::delete('/{server}', [ServerController::class, 'destroy']);
        Route::post('/{server}/test-connection', [ServerController::class, 'testConnection']);
    });

    // Gestion des Applications
    Route::prefix('applications')->group(function () {
        // ... (routes applications)
        Route::get('/', [ApplicationController::class, 'index']);
        Route::post('/', [ApplicationController::class, 'store']);
        Route::get('/{id}', [ApplicationController::class, 'show']);

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
        Route::post('/{database}/deploy', [DatabaseController::class, 'deploy']);

        // Logs de Runtime des Bases de données
        Route::prefix('{database}/logs')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\DatabaseLogController::class, 'index']);
            Route::post('/stream', [\App\Http\Controllers\Api\DatabaseLogController::class, 'stream']);
        });
    });
});

Route::get('/github/auth/callback', [\App\Http\Controllers\Api\GitHubApiController::class, 'callback']);
