<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GitHubService;
use Illuminate\Http\Request;

use Laravel\Socialite\Facades\Socialite;

class GitHubApiController extends Controller
{
    public function __construct(protected GitHubService $gitHub) {}

    /**
     * Redirige l'utilisateur vers GitHub pour l'authentification.
     */
    public function redirect(Request $request)
    {
        $user = auth()->user();
        
        if (!$user) {
            return response()->json(['error' => 'Non authentifié'], 401);
        }

        $state = \Illuminate\Support\Str::random(40);
        
        // Stocker l'ID utilisateur dans le cache avec la clé state (valide 10 min)
        \Illuminate\Support\Facades\Cache::put('gh_auth_' . $state, $user->id, 600);

        $url = Socialite::driver('github')
            ->scopes(['repo', 'user'])
            ->stateless()
            ->with(['state' => $state])
            ->redirect()
            ->getTargetUrl();

        return response()->json(['url' => $url]);
    }

    /**
     * Gère le retour de GitHub après l'authentification.
     */
    public function callback(\Illuminate\Http\Request $request)
    {
        \Illuminate\Support\Facades\Log::info('GitHub Callback Reached', ['state' => $request->state]);
        try {
            $state = $request->state;
            
            if (!$state || !\Illuminate\Support\Facades\Cache::has('gh_auth_' . $state)) {
                throw new \Exception("Session OAuth expirée ou invalide.");
            }

            $userId = \Illuminate\Support\Facades\Cache::pull('gh_auth_' . $state);
            $user = User::findOrFail($userId);
            \Illuminate\Support\Facades\Log::info('GitHub Callback User Found', ['user_id' => $user->id]);

            $githubUser = Socialite::driver('github')->stateless()->user();
            \Illuminate\Support\Facades\Log::info('GitHub OAuth Success', ['github_id' => $githubUser->getId()]);
            
            $user->update([
                'github_id' => $githubUser->getId(),
                'github_token' => $githubUser->token,
                'github_refresh_token' => $githubUser->refreshToken,
            ]);

            // Redirection vers le dashboard (settings/integrations) sans token dans l'URL
            $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');
            return redirect()->to($frontendUrl . '/settings/integrations?success=github');
            
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('GitHub Callback Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');
            $errorMessage = $e->getMessage();
            
            // Si c'est une erreur de clé de chiffrement (common on VPS)
            if (str_contains($errorMessage, 'MAC')) {
                $errorMessage = "Erreur de clé de chiffrement (APP_KEY). Contactez l'admin.";
            }

            return redirect()->to($frontendUrl . '/settings/integrations?error=' . urlencode($errorMessage));
        }
    }

    /**
     * Retourne les infos du compte GitHub connecté.
     */
    public function user()
    {
        $user = auth()->user();
        
        if (!$user) {
            \Illuminate\Support\Facades\Log::warning('GitHub User Check: Unauthenticated access attempt');
            return response()->json(['error' => 'Non authentifié (Sanctum)', 'connected' => false], 401);
        }

        \Illuminate\Support\Facades\Log::debug('GitHub User Check', [
            'user_id' => $user->id,
            'email' => $user->email,
            'has_token' => !empty($user->github_token)
        ]);
        
        try {
            $info = $this->gitHub->getUserInfo($user);
            return response()->json($info);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('GitHub User Info Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage(), 'connected' => false], 200);
        }
    }

    /**
     * Liste les dépôts GitHub de l'utilisateur.
     */
    public function repositories()
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['error' => 'Non authentifié'], 401);
        }

        try {
            return response()->json($this->gitHub->getRepositories($user));
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 428);
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

        $user = auth()->user();

        if (!$user) {
            return response()->json(['error' => 'Non authentifié'], 401);
        }

        try {
            return response()->json($this->gitHub->getBranches(
                $user, 
                $request->owner, 
                $request->repo
            ));
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 428);
        }
    }
}
