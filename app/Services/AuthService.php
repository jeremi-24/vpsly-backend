<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

class AuthService
{
    /**
     * Gère la création/mise à jour de l'utilisateur OAuth et génère un token Sanctum.
     */
    public function handleOAuthUser($socialUser, string $provider)
    {
        $user = User::updateOrCreate(
            [
                'email' => $socialUser->getEmail(),
            ],
            [
                'name' => $socialUser->getName() ?? $socialUser->getNickname(),
                'avatar' => $socialUser->getAvatar(),
                "{$provider}_id" => $socialUser->getId(),
                "{$provider}_token" => $socialUser->token,
                // On s'assure qu'un mot de passe existe pour la cohérence Authenticatable
                'password' => bcrypt(str()->random(24)),
            ]
        );

        // Connexion optionnelle côté backend pour certaines fonctionnalités Socialite si nécessaire
        Auth::login($user);

        // Génération du token pour le Dashboard React
        $token = $user->createToken('auth')->plainTextToken;

        return [
            'user' => $user,
            'token' => $token,
        ];
    }

    /**
     * Construit l'URL de redirection vers le dashboard.
     */
    public function buildRedirect(string $token): string
    {
        $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');
        return "{$frontendUrl}/auth/callback?token={$token}";
    }
}
