<?php

namespace App\Services;

use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Http;

class GitHubService
{
    protected string $baseUrl = 'https://api.github.com';

    /**
     * Récupère la liste des dépôts (publics et privés) de l'utilisateur connecté via son token GitHub.
     */
    public function getRepositories(User $user)
    {
        if (!$user->github_token) {
            throw new Exception("Compte GitHub non connecté.");
        }

        $response = Http::withToken($user->github_token)
            ->get("{$this->baseUrl}/user/repos", [
                'sort' => 'updated',
                'per_page' => 100,
            ]);

        if (!$response->successful()) {
            throw new Exception("Erreur GitHub API : " . $response->body());
        }

        return $response->json();
    }

    /**
     * Récupère la liste des branches pour un dépôt spécifique.
     */
    public function getBranches(User $user, string $owner, string $repo)
    {
        if (!$user->github_token) {
            throw new Exception("Compte GitHub non connecté.");
        }

        $response = Http::withToken($user->github_token)
            ->get("{$this->baseUrl}/repos/{$owner}/{$repo}/branches");

        if (!$response->successful()) {
            throw new Exception("Erreur GitHub API : " . $response->body());
        }

        return $response->json();
    }

    /**
     * Récupère le contenu d'un répertoire (par défaut la racine).
     */
    public function getRepositoryContents(User $user, string $owner, string $repo, string $path = '')
    {
        if (!$user->github_token) {
            throw new Exception("Compte GitHub non connecté.");
        }

        $response = Http::withToken($user->github_token)
            ->withHeaders(['User-Agent' => 'VPSLY-Engine'])
            ->get("{$this->baseUrl}/repos/{$owner}/{$repo}/contents/{$path}");

        if (!$response->successful()) {
            throw new Exception("Erreur GitHub API (Contents) : " . $response->body());
        }

        return $response->json();
    }

    /**
     * Récupère les informations de l'utilisateur GitHub.
     */
    public function getUserInfo(User $user)
    {
        if (!$user->github_token) {
            return ['connected' => false];
        }

        $response = Http::withToken($user->github_token)
            ->withHeaders(['User-Agent' => 'VPSLY-Engine'])
            ->get("{$this->baseUrl}/user");

        if (!$response->successful()) {
            if ($response->status() === 401) {
                // Token invalide ou expiré
                $user->update([
                    'github_token' => null,
                    'github_id' => null,
                ]);
                return ['connected' => false];
            }
            throw new Exception("Erreur GitHub API (User) : " . $response->body());
        }

        $data = $response->json();
        return [
            'connected' => true,
            'id' => $data['id'],
            'login' => $data['login'],
            'avatar_url' => $data['avatar_url'],
            'name' => $data['name'],
        ];
    }

    /**
     * Enregistre un webhook pour un dépôt.
     */
    public function createWebhook(User $user, string $owner, string $repo, string $callbackUrl, ?string $secret = null): int
    {
        if (!$user->github_token) {
            throw new Exception("Compte GitHub non connecté.");
        }

        $response = Http::withToken($user->github_token)
            ->withHeaders(['User-Agent' => 'VPSLY-Engine'])
            ->post("{$this->baseUrl}/repos/{$owner}/{$repo}/hooks", [
                'name' => 'web',
                'active' => true,
                'events' => ['push'],
                'config' => [
                    'url' => $callbackUrl,
                    'content_type' => 'json',
                    'insecure_ssl' => '0',
                    'secret' => $secret ?? config('app.webhook_secret', 'vpsly_secret_key'),
                ],
            ]);

        if (!$response->successful()) {
            throw new Exception("Erreur création Webhook GitHub : " . $response->body());
        }

        return $response->json('id');
    }

    /**
     * Supprime un webhook.
     */
    public function deleteWebhook(User $user, string $owner, string $repo, int $hookId): void
    {
        if (!$user->github_token) {
            return;
        }

        $response = Http::withToken($user->github_token)
            ->withHeaders(['User-Agent' => 'VPSLY-Engine'])
            ->delete("{$this->baseUrl}/repos/{$owner}/{$repo}/hooks/{$hookId}");

        if (!$response->successful() && $response->status() !== 404) {
            throw new Exception("Erreur suppression Webhook GitHub : " . $response->body());
        }
    }
}
