<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\DeploymentController;

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
        Route::get('/', [ApplicationController::class, 'index']);
        Route::post('/', [ApplicationController::class, 'store']);
    });

    // Déploiements
    Route::prefix('deployments')->group(function () {
        Route::post('/', [DeploymentController::class, 'store']);
        Route::get('/{id}/status', [DeploymentController::class, 'status']);
        Route::get('/{id}/logs', [DeploymentController::class, 'logs']);
    });
});

Route::prefix('github')->group(function () {
    Route::get('/user', [\App\Http\Controllers\Api\GitHubApiController::class, 'user']);
    Route::get('/repositories', [\App\Http\Controllers\Api\GitHubApiController::class, 'repositories']);
    Route::get('/branches', [\App\Http\Controllers\Api\GitHubApiController::class, 'branches']);
});
