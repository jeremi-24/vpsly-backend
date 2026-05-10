<?php

namespace App\Services\Deployment;

use App\Models\Server;
use Exception;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;
use Illuminate\Support\Facades\Log;


class SSHService
{
    protected ?SSH2 $ssh = null;
    protected $connectionData = null;

    public function connect(Server $server): self
    {
        Log::info("[SSH] Attempting connection to {$server->ip}:{$server->ssh_port}...");
        $this->ssh = new SSH2($server->ip, $server->ssh_port);
        
        // Timeout pour la connexion initiale
        $this->ssh->setTimeout(10);
        
        Log::info("[SSH] Loading private key...");
        $key = PublicKeyLoader::load($server->ssh_private_key);

        Log::info("[SSH] Starting login for user: {$server->ssh_user}...");
        if (!$this->ssh->login($server->ssh_user, $key)) {
            Log::error("[SSH] Authentication failed for {$server->ip}");
            throw new Exception("SSH connection failed for server {$server->name} ({$server->ip})");
        }
        
        Log::info("[SSH] Login successful.");
        
        // On garde les infos pour SFTP si besoin d'upload
        $this->connectionData = [
            'host' => $server->ip,
            'port' => $server->ssh_port,
            'user' => $server->ssh_user,
            'key'  => $key
        ];

        
        // Timeout par défaut pour éviter de bloquer PHP-FPM (15s)
        $this->ssh->setTimeout(15);

        return $this;
    }

    /**
     * Définit le timeout de la connexion SSH.
     */
    public function setTimeout(int $seconds): void
    {
        if ($this->ssh) {
            $this->ssh->setTimeout($seconds);
        }
    }

    /**
     * Récupère le timeout actuel.
     */
    public function getTimeout(): int
    {
        return $this->ssh ? $this->ssh->getTimeout() : 0;
    }

    /**
     * Exécute une commande de manière synchrone et retourne l'output.
     */
    public function exec(string $command): string
    {
        if (!$this->ssh) {
            throw new Exception("SSH not connected.");
        }

        $output = $this->ssh->exec($command . ' 2>&1');
        
        if ($this->ssh->getExitStatus() !== 0) {
           throw new Exception("Command failed: {$command}\nOutput: {$output}");
        }

        return $output ?: '';
    }

    /**
     * Exécute une commande et stream l'output ligne par ligne.
     */
    public function stream(string $command, callable $onLine): void
    {
        if (!$this->ssh) {
            throw new Exception("SSH not connected.");
        }

        $buffer = '';

        $this->ssh->exec($command . ' 2>&1', function ($str) use ($onLine, &$buffer) {
            $buffer .= $str;
            
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                
                $line = str_replace("\r", "", $line);
                if (strlen($line) > 0) {
                    $onLine($line);
                }
            }
        });
        
        // Traitement du reliquat
        $buffer = str_replace("\r", "", trim($buffer));
        if (strlen($buffer) > 0) {
            $onLine($buffer);
        }
        
        if ($this->ssh->getExitStatus() !== 0) {
            throw new Exception("Command stream failed (Exit: " . $this->ssh->getExitStatus() . "): {$command}");
        }
    }

    public function download(string $remotePath): string
    {
        if (!$this->ssh) {
            throw new Exception("SSH not connected.");
        }

        $output = $this->ssh->exec("cat " . escapeshellarg($remotePath));
        
        if ($this->ssh->getExitStatus() !== 0) {
            throw new Exception("Download failed: cat command returned status " . $this->ssh->getExitStatus());
        }

        return $output;
    }

    /**
     * Télécharge un fichier distant vers un chemin local via SFTP.
     */
    public function downloadToFile(string $remotePath, string $localPath): void
    {
        if (!$this->connectionData) {
            throw new Exception("SSH connection data missing. Call connect() first.");
        }

        Log::info("[SFTP] Downloading remote file {$remotePath} to {$localPath}...");
        
        $sftp = new \phpseclib3\Net\SFTP($this->connectionData['host'], $this->connectionData['port']);
        if (!$sftp->login($this->connectionData['user'], $this->connectionData['key'])) {
            throw new Exception("SFTP authentication failed.");
        }

        if (!$sftp->get($remotePath, $localPath)) {
            throw new Exception("SFTP download failed for path: {$remotePath}");
        }

        Log::info("[SFTP] Download successful.");
    }

    public function disconnect(): void
    {
        if ($this->ssh) {
            $this->ssh->disconnect();
            $this->ssh = null;
        }
    }

    /**
     * Upload un fichier sur le serveur via SFTP à partir d'un chemin local.
     */
    public function uploadFile(string $remotePath, string $localPath): void
    {
        if (!$this->connectionData) {
            throw new Exception("SSH connection data missing. Call connect() first.");
        }

        Log::info("[SFTP] Uploading local file {$localPath} to {$remotePath}...");
        
        $sftp = new \phpseclib3\Net\SFTP($this->connectionData['host'], $this->connectionData['port']);
        if (!$sftp->login($this->connectionData['user'], $this->connectionData['key'])) {
            throw new Exception("SFTP authentication failed.");
        }

        // SFTP::SOURCE_LOCAL_FILE permet de streamer le fichier depuis le disque
        if (!$sftp->put($remotePath, $localPath, \phpseclib3\Net\SFTP::SOURCE_LOCAL_FILE)) {
            throw new Exception("SFTP upload failed for path: {$remotePath}");
        }

        Log::info("[SFTP] Upload successful.");
    }

    /**
     * Upload du contenu brut sur le serveur via SFTP.
     */
    public function upload(string $remotePath, string $content): void
    {
        if (!$this->connectionData) {
            throw new Exception("SSH connection data missing. Call connect() first.");
        }

        Log::info("[SFTP] Uploading file to {$remotePath}...");
        
        $sftp = new \phpseclib3\Net\SFTP($this->connectionData['host'], $this->connectionData['port']);
        if (!$sftp->login($this->connectionData['user'], $this->connectionData['key'])) {
            throw new Exception("SFTP authentication failed.");
        }

        if (!$sftp->put($remotePath, $content)) {
            throw new Exception("SFTP upload failed for path: {$remotePath}");
        }

        Log::info("[SFTP] Upload successful.");
    }
}
