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
        $user = User::where('email', $socialUser->getEmail())->first();

        if ($user) {
            $user->update([
                'avatar' => $socialUser->getAvatar(),
                "{$provider}_id" => $socialUser->getId(),
                "{$provider}_token" => $socialUser->token,
            ]);
        } else {
            $user = User::create([
                'name' => $socialUser->getName() ?? $socialUser->getNickname(),
                'email' => $socialUser->getEmail(),
                'avatar' => $socialUser->getAvatar(),
                "{$provider}_id" => $socialUser->getId(),
                "{$provider}_token" => $socialUser->token,
                'password' => bcrypt(str()->random(24)),
            ]);
        }

        // Si l'utilisateur vient d'être créé, on envoie le mail de bienvenue
        if ($user->wasRecentlyCreated) {
            $user->notify(new \App\Notifications\WelcomeNotification($user));
        }

        // Alerte de sécurité pour la connexion (uniquement si l'IP change)
        $currentIp = request()->ip();
        if ($user->last_login_ip !== $currentIp) {
            $user->notify(new \App\Notifications\LoginSecurityNotification([
                'ip' => $currentIp,
                'user_agent' => request()->userAgent(),
            ]));

            $user->update(['last_login_ip' => $currentIp]);
        }

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
