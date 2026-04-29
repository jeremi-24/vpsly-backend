<?php

namespace App\Services\Backup;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Illuminate\Support\Facades\Log;

class GoogleDriveService
{
    protected $client;
    protected $service;
    protected $settings;

    /**
     * Initialise le client avec les tokens OAuth2 de l'utilisateur
     */
    public function __construct(\App\Models\BackupSetting $settings)
    {
        $this->settings = $settings;
        $credentials = $settings->storage_credentials;

        $this->client = new Client();
        $this->client->setClientId(config('services.google.client_id'));
        $this->client->setClientSecret(config('services.google.client_secret'));
        $this->client->setAccessToken($credentials['access_token']);

        // Gérer le refresh token si expiré
        if ($this->client->isAccessTokenExpired()) {
            if (isset($credentials['refresh_token'])) {
                $newToken = $this->client->fetchAccessTokenWithRefreshToken($credentials['refresh_token']);
                
                // Sauvegarder le nouveau token en base
                $credentials['access_token'] = $newToken['access_token'];
                if (isset($newToken['refresh_token'])) {
                    $credentials['refresh_token'] = $newToken['refresh_token'];
                }
                $this->settings->update(['storage_credentials' => $credentials]);
                
                $this->client->setAccessToken($newToken);
            }
        }

        $this->client->addScope(Drive::DRIVE_FILE);
        $this->service = new Drive($this->client);
    }

    /**
     * Upload un fichier vers Google Drive avec structure : vpsly_backups / {appName} / {file}
     */
    public function uploadFile(string $filePath, string $remoteName, ?string $appName = null)
    {
        try {
            // 1. Dossier racine "vpsly_backups"
            $rootFolderId = $this->getOrCreateFolder('vpsly_backups');
            $targetFolderId = $rootFolderId;

            // 2. Sous-dossier au nom de l'application
            if ($appName) {
                $targetFolderId = $this->getOrCreateFolder($appName, $rootFolderId);
            }

            $fileMetadata = new DriveFile([
                'name' => $remoteName,
                'parents' => [$targetFolderId]
            ]);

            $content = file_get_contents($filePath);
            $mimeType = mime_content_type($filePath);

            $file = $this->service->files->create($fileMetadata, [
                'data' => $content,
                'mimeType' => $mimeType,
                'uploadType' => 'multipart',
                'fields' => 'id'
            ]);

            return $file->id;
        } catch (\Exception $e) {
            Log::error("Erreur d'upload Google Drive : " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Récupère ou crée un dossier par son nom
     */
    protected function getOrCreateFolder(string $folderName, ?string $parentId = null): string
    {
        $query = "name='{$folderName}' and mimeType='application/vnd.google-apps.folder' and trashed=false";
        if ($parentId) {
            $query .= " and '{$parentId}' in parents";
        }

        $results = $this->service->files->listFiles([
            'q' => $query,
            'fields' => 'files(id)',
        ]);

        if (count($results->getFiles()) > 0) {
            return $results->getFiles()[0]->getId();
        }

        $meta = new DriveFile([
            'name' => $folderName,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => $parentId ? [$parentId] : [],
        ]);

        $folder = $this->service->files->create($meta, ['fields' => 'id']);
        return $folder->id;
    }

    /**
     * Récupère le contenu d'un fichier depuis Google Drive
     */
    public function getFileContent(string $fileId)
    {
        try {
            $response = $this->service->files->get($fileId, ['alt' => 'media']);
            return $response->getBody()->getContents();
        } catch (\Exception $e) {
            Log::error("Erreur de téléchargement Google Drive : " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Supprime un fichier sur Google Drive
     */
    public function deleteFile(string $fileId)
    {
        try {
            $this->service->files->delete($fileId);
            return true;
        } catch (\Exception $e) {
            Log::error("Erreur de suppression Google Drive : " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Teste la connectivité
     */
    public function testConnection()
    {
        try {
            $this->service->about->get(['fields' => 'user']);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
