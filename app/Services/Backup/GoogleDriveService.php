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

    /**
     * Initialise le client avec les credentials JSON du compte de service
     */
    public function __construct(array $credentials)
    {
        $this->client = new Client();
        $this->client->setAuthConfig($credentials);
        $this->client->addScope(Drive::DRIVE_FILE);
        
        $this->service = new Drive($this->client);
    }

    /**
     * Upload un fichier vers Google Drive
     */
    public function uploadFile(string $filePath, string $remoteName, ?string $folderId = null)
    {
        try {
            $fileMetadata = new DriveFile([
                'name' => $remoteName,
                'parents' => $folderId ? [$folderId] : []
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
