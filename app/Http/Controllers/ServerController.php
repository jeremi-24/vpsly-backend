<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\User;
use App\Services\ServerKeyService;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    public function index()
    {
        // Simple MVP : On prend tous les serveurs ou ceux du user 1 par defaut
        $user = User::firstOrCreate(
            ['email' => 'admin@vpsly.local'],
            ['name' => 'Admin', 'password' => bcrypt('password')]
        );

        return response()->json(Server::where('user_id', $user->id)->get());
    }

    public function store(Request $request, ServerKeyService $keyService)
    {
        $request->validate([
            'ip' => 'required|ip',
            'name' => 'required|string|max:255',
        ]);

        $user = User::firstOrCreate(
            ['email' => 'admin@vpsly.local'],
            ['name' => 'Admin', 'password' => bcrypt('password')]
        );

        // 1. Generation magique des clés
        $keys = $keyService->generateKeyPair();

        // 2. Sauvegarde du Serveur avec la clé privée protégée
        $server = Server::create([
            'user_id' => $user->id,
            'name' => $request->name,
            'ip' => $request->ip,
            'ssh_user' => 'root', // root par defaut pour setup
            'ssh_port' => 22,
            'ssh_private_key' => $keys['private_key'],
        ]);

        // 3. Renvoi des instructions d'install au client
        $instruction = $keyService->getInstallCommand($keys['public_key']);

        return response()->json([
            'message' => 'Server created successfully',
            'server' => $server,
            'setup_command' => $instruction
        ], 201);
    }
}
