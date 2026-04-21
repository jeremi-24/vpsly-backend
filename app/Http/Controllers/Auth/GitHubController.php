<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class GitHubController extends Controller
{
    /**
     * Redirige vers GitHub avec les scopes repo pour pouvoir lister les projets privés.
     */
    public function redirectToGithub()
    {
        return Socialite::driver('github')
            ->scopes(['repo', 'read:user'])
            ->redirect();
    }

    /**
     * Gère le retour de GitHub.
     */
    public function handleGithubCallback()
    {
        try {
            $githubUser = Socialite::driver('github')->user();
            
            // Si l'utilisateur est déjà connecté, on lie son compte
            // Sinon on essaie de trouver par email ou on crée
            $user = Auth::user() ?? User::where('email', $githubUser->email)->first();

            if (!$user) {
                $user = User::create([
                    'name' => $githubUser->name ?? $githubUser->nickname,
                    'email' => $githubUser->email,
                    'password' => bcrypt(str()->random(24)),
                ]);
            }

            $user->update([
                'github_id' => $githubUser->id,
                'github_nickname' => $githubUser->nickname,
                'github_token' => $githubUser->token,
            ]);

            if (!Auth::check()) {
                Auth::login($user);
            }

            // Génération du token pour le dashboard
            $token = $user->createToken('vpsly-auth-token')->plainTextToken;

            $dashboardUrl = config('app.frontend_url', 'http://localhost:5173') . '/auth/callback?token=' . $token;

            return redirect($dashboardUrl);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'Authentification GitHub échouée.',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
