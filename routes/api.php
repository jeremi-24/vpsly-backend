<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\DeploymentController;

// Si vous utilisez Sanctum avec Auth : Route::middleware('auth:sanctum')->get('/user', function () { ... });

// MVP API Endpoints sans middleware "auth" complexe externe pour simplifier les appels POST du End-User.
// Dans un vrai SaaS, vous engloberiez ça dans un middleware auth:sanctum
Route::prefix('servers')->group(function () {
    Route::get('/', [ServerController::class, 'index']);
    Route::post('/', [ServerController::class, 'store']);
});

Route::prefix('applications')->group(function () {
    Route::get('/', [ApplicationController::class, 'index']);
    Route::post('/', [ApplicationController::class, 'store']);
});

Route::prefix('deployments')->group(function () {
    Route::post('/', [DeploymentController::class, 'store']);
    Route::get('/{id}/status', [DeploymentController::class, 'status']);
    Route::get('/{id}/logs', [DeploymentController::class, 'logs']);
});

Route::prefix('github')->group(function () {
    Route::get('/user', [\App\Http\Controllers\Api\GitHubApiController::class, 'user']);
    Route::get('/repositories', [\App\Http\Controllers\Api\GitHubApiController::class, 'repositories']);
    Route::get('/branches', [\App\Http\Controllers\Api\GitHubApiController::class, 'branches']);
});
