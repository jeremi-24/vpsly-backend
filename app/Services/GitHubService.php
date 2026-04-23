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
}
