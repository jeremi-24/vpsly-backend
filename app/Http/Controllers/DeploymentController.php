<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Deployment;
use App\Jobs\DeployApplicationJob;
use Illuminate\Http\Request;

class DeploymentController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'application_id' => 'required|exists:applications,id',
        ]);

        $app = Application::findOrFail($request->application_id);

        // Anti-concurrence : On rejette si un déploiement est déjà en cours
        if ($app->is_deploying) {
            $deployment = Deployment::create([
                'application_id' => $app->id,
                'status' => 'failed',
                'finished_at' => now(),
            ]);
            
            \App\Models\DeploymentLog::create([
                'deployment_id' => $deployment->id,
                'line' => 'Deployment locked: Another process is already running for this application.',
                'type' => 'error'
            ]);

            return response()->json([
                'message' => 'Un déploiement est déjà en cours pour cette application.',
                'deployment' => $deployment
            ], 422);
        }

        // 1. Initialisation de la State Machine
        $app->update(['is_deploying' => true]);

        $deployment = Deployment::create([
            'application_id' => $app->id,
            'status' => 'pending',
        ]);

        // 2. Dispatch asynchrone du coeur (le front ne pendouille pas)
        DeployApplicationJob::dispatch($deployment->id);

        return response()->json([
            'message' => 'Deployment queued successfully',
            'deployment' => $deployment,
            'application' => $app->fresh()
        ], 202);
    }

    public function status($id)
    {
        $deployment = Deployment::findOrFail($id);

        return response()->json([
            'status' => $deployment->status,
            'started_at' => $deployment->started_at,
            'finished_at' => $deployment->finished_at,
        ]);
    }

    public function logs($id)
    {
        $deployment = Deployment::findOrFail($id);

        return response()->json([
            'deployment_id' => $deployment->id,
            'status' => $deployment->status,
            'logs' => $deployment->logs
        ]);
    }
}
