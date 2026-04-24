<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\User;
use App\Models\Deployment;
use App\Jobs\DeployApplicationJob;
use Illuminate\Http\Request;

class ApplicationController extends Controller
{
    public function index()
    {
        $user = auth()->user() ?? User::first();
        return response()->json(
            Application::with('server')
                ->where('user_id', $user->id)
                ->latest()
                ->get()
        );
    }

    public function store(Request $request)
    {
        $request->validate([
            'server_id' => 'required|exists:servers,id',
            'name' => 'required|string|unique:applications,name',
            'repo_url' => 'required|url',
            'branch' => 'nullable|string',
            'domain' => 'nullable|string',
            'preset' => 'nullable|string', // Ajout du preset
        ]);

        $user = auth()->user() ?? User::first();

        // Utilisation de notre nouvelle action atomique via le container
        $result = app(\App\Actions\Deployment\CreateAtomicStack::class)->execute([
            'user_id' => $user->id,
            'server_id' => $request->server_id,
            'name' => $request->name,
            'repo_url' => $request->repo_url,
            'branch' => $request->branch ?? 'main',
            'domain' => $request->domain,
            'preset' => $request->preset ?? 'generic',
        ]);

        return response()->json([
            'message' => 'Stack atomique créée avec succès. Déploiement en cours...',
            'application' => $result['application']->load('server'),
            'deployment_id' => $result['deployment']->id
        ], 201);
    }

    public function show($id)
    {
        $user = auth()->user() ?? \App\Models\User::first();
        $app = Application::with([
                'server',
                'deployments' => fn($q) => $q->latest()->limit(5),
                'environmentVariables',
                'databases',
            ])
            ->where('user_id', $user->id)
            ->findOrFail($id);

        return response()->json($app);
    }
}

