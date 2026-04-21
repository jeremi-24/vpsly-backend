<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

use App\Services\AuthService;

class GoogleController extends Controller
{
    /**
     * Redirige l'utilisateur vers la page d'authentification de Google.
     */
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Gère le retour de Google.
     */
    public function handleGoogleCallback(AuthService $authService)
    {
        try {
            $driver = Socialite::driver('google');

            // Patch pour le développement local
            if (app()->environment('local')) {
                $driver->setHttpClient(new \GuzzleHttp\Client(['verify' => false]));
            }

            $socialUser = $driver->user();
            
            $result = $authService->handleOAuthUser($socialUser, 'google');

            return redirect($authService->buildRedirect($result['token']));

        } catch (Exception $e) {
            return response()->json([
                'error' => 'Authentification Google échouée.',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
