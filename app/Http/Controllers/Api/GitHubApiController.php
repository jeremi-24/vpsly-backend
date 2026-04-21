<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GitHubService;
use Illuminate\Http\Request;

class GitHubApiController extends Controller
{
    public function __construct(protected GitHubService $gitHub) {}

    /**
     * Retourne les infos du compte GitHub connecté.
     */
    public function user()
    {
        // MVP: On prend l'admin par defaut ou le user connecté
        $user = auth()->user() ?? User::first();
        
        try {
            return response()->json($this->gitHub->getUserInfo($user));
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 401);
        }
    }

    /**
     * Liste les dépôts GitHub de l'utilisateur.
     */
    public function repositories()
    {
        $user = auth()->user() ?? User::first();

        try {
            return response()->json($this->gitHub->getRepositories($user));
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 401);
        }
    }

    /**
     * Liste les branches d'un dépôt.
     */
    public function branches(Request $request)
    {
        $request->validate([
            'owner' => 'required|string',
            'repo' => 'required|string',
        ]);

        $user = auth()->user() ?? User::first();

        try {
            return response()->json($this->gitHub->getBranches(
                $user, 
                $request->owner, 
                $request->repo
            ));
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 401);
        }
    }
}
