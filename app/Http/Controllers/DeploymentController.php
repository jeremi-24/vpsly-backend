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

        // 1. Initialisation de la State Machine
        $deployment = Deployment::create([
            'application_id' => $app->id,
            'status' => 'pending',
        ]);

        // 2. Dispatch asynchrone du coeur (le front ne pendouille pas)
        DeployApplicationJob::dispatch($deployment->id);

        return response()->json([
            'message' => 'Deployment queued successfully',
            'deployment' => $deployment
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
        $deployment = Deployment::with('logs')->findOrFail($id);

        return response()->json([
            'deployment_id' => $deployment->id,
            'status' => $deployment->status,
            'logs' => $deployment->logs
        ]);
    }
}
