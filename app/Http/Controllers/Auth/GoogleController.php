<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

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
    public function handleGoogleCallback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();
            
            $user = User::updateOrCreate([
                'email' => $googleUser->email,
            ], [
                'name' => $googleUser->name,
                'google_id' => $googleUser->id,
                'avatar' => $googleUser->avatar,
                'google_token' => $googleUser->token,
                // On ne touche pas au mot de passe s'il existe déjà
                // S'il n'existe pas (création), on peut mettre un truc aléatoire inutilisable
                'password' => $googleUser->password ?? bcrypt(str()->random(24)),
            ]);

            Auth::login($user);

            // Génération du token Sanctum pour le frontend
            $token = $user->createToken('vpsly-auth-token')->plainTextToken;

            // Redirection vers le dashboard (vpsly-dashboard) avec le token
            // NB: En production, on passera par une URL sécurisée ou un cookie
            $dashboardUrl = config('app.frontend_url', 'http://localhost:5173') . '/auth/callback?token=' . $token;

            return redirect($dashboardUrl);

        } catch (Exception $e) {
            return response()->json([
                'error' => 'Authentification Google échouée.',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
