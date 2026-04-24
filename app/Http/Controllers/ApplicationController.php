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
        ]);

        $user = auth()->user() ?? User::first();

        // Création de l'app si elle n'existe pas (le validator gère l'unique:name)
        $app = Application::create([
            'user_id' => $user->id,
            'server_id' => $request->server_id,
            'name' => $request->name,
            'repo_url' => $request->repo_url,
            'branch' => $request->branch ?? 'main',
            'domain' => $request->domain,
            'status' => 'pending',
            'is_deploying' => false,
        ]);

        // Création du déploiement initial
        $deployment = Deployment::create([
            'application_id' => $app->id,
            'status' => 'pending',
        ]);

        // Déclenchement du job de déploiement
        DeployApplicationJob::dispatch($deployment->id);

        return response()->json([
            'message' => 'Application créée avec succès. Déploiement en cours...',
            'application' => $app->load('server'),
            'deployment_id' => $deployment->id
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

