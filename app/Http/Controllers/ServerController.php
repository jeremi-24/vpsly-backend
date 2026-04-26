<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\User;
use App\Services\ServerKeyService;
use Illuminate\Http\Request;

use App\Services\SshService;

class ServerController extends Controller
{
    protected $sshService;

    public function __construct(SshService $sshService)
    {
        $this->sshService = $sshService;
    }

    /**
     * Liste les serveurs de l'utilisateur connecté.
     */
    public function index()
    {
        return response()->json(
            Server::where('user_id', auth()->id())->get()
        );
    }

    /**
     * Ajoute un nouveau serveur (en état pending).
     */
    public function store(Request $request, ServerKeyService $keyService)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'ip' => 'required|ip',
            'ssh_user' => 'required|string|alpha_dash',
            'ssh_port' => 'required|integer|min:1|max:65535',
        ]);

        $keys = $keyService->generateKeyPair();

        $server = Server::create([
            'user_id' => auth()->id(),
            'name' => $data['name'],
            'ip' => $data['ip'],
            'ssh_user' => $data['ssh_user'],
            'ssh_port' => $data['ssh_port'],
            'ssh_private_key' => $keys['private_key'],
            'status' => 'pending',
        ]);

        $instruction = $keyService->getInstallCommand($keys['public_key']);

        return response()->json([
            'message' => 'Server created successfully',
            'server' => $server,
            'setup_command' => $instruction
        ], 201);
    }

    /**
     * Teste la connexion SSH et met à jour le statut.
     */
    public function testConnection(Server $server)
    {
        $this->authorizeOwner($server);

        try {
            $this->sshService->connect($server);
            $server->update(['status' => 'connected']);
            
            return response()->json([
                'status' => 'connected',
                'message' => 'SSH connection successful!'
            ]);
        } catch (\Exception $e) {
            $server->update(['status' => 'failed']);
            
            return response()->json([
                'status' => 'failed',
                'message' => 'SSH connection failed',
                'details' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Affiche un serveur spécifique.
     */
    public function show(Server $server)
    {
        $this->authorizeOwner($server);

        return response()->json($server);
    }

    /**
     * Met à jour les informations du serveur.
     */
    public function update(Request $request, Server $server)
    {
        $this->authorizeOwner($server);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'ip' => 'sometimes|required|ip',
            'ssh_user' => 'sometimes|required|string|alpha_dash',
            'ssh_port' => 'sometimes|required|integer|min:1|max:65535',
        ]);

        $server->update($data);

        return response()->json([
            'message' => 'Server updated successfully',
            'server' => $server
        ]);
    }

    /**
     * Supprime un serveur.
     */
    public function destroy(Server $server)
    {
        $this->authorizeOwner($server);

        // Protection contre la suppression si lié à des apps
        if ($server->applications()->exists()) {
            return response()->json([
                'error' => 'Impossible de supprimer le serveur : des applications y sont encore rattachées.'
            ], 409);
        }

        $server->delete();

        return response()->json([
            'message' => 'Server deleted successfully'
        ]);
    }

    /**
     * Nettoie le serveur (docker system prune).
     */
    public function prune(Server $server, \App\Services\Deployment\DockerService $docker)
    {
        $this->authorizeOwner($server);

        try {
            $output = $docker->prune($server);
            return response()->json([
                'message' => 'Nettoyage du serveur effectué avec succès.',
                'output' => $output
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Échec du nettoyage : ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Vérifie que l'utilisateur est bien le propriétaire.
     */
    protected function authorizeOwner(Server $server)
    {
        if ($server->user_id !== auth()->id()) {
            abort(403, 'Accès non autorisé à ce serveur.');
        }
    }
}
