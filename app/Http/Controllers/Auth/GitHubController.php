<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

use App\Services\AuthService;

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
    public function handleGithubCallback(AuthService $authService)
    {
        try {
            $driver = Socialite::driver('github');

            // Patch pour le développement local
            if (app()->environment('local')) {
                $driver->setHttpClient(new \GuzzleHttp\Client(['verify' => false]));
            }

            $socialUser = $driver->user();
            
            $result = $authService->handleOAuthUser($socialUser, 'github');

            return redirect($authService->buildRedirect($result['token']));

        } catch (Exception $e) {
            return response()->json([
                'error' => 'Authentification GitHub échouée.',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
