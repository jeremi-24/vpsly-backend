<?php

namespace App\Services;

use App\Models\User;
use App\Models\Team;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

            // Création d'une équipe personnelle par défaut
            $this->createPersonalTeam($user);
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

    /**
     * Crée une équipe personnelle pour l'utilisateur.
     */
    protected function createPersonalTeam(User $user)
    {
        return DB::transaction(function () use ($user) {
            $team = Team::create([
                'name' => $user->name . "'s Team",
                'owner_id' => $user->id,
            ]);

            $user->teams()->attach($team->id, ['role' => 'owner']);
            $user->update(['current_team_id' => $team->id]);

            return $team;
        });
    }
}
