<?php

namespace App\Services;

use App\Models\Server;
use Exception;
use phpseclib3\Net\SSH2;
use phpseclib3\Net\SFTP;
use phpseclib3\Crypt\PublicKeyLoader;

class SshService
{
    protected ?SSH2 $ssh = null;

    public function connect(Server $server): void
    {
        $this->ssh = new SSH2($server->ip, $server->ssh_port);
        
        // Timeout pour éviter les freezes éternels
        $this->ssh->setTimeout(10);
        
        $key = PublicKeyLoader::load($server->ssh_private_key);

        if (!$this->ssh->login($server->ssh_user, $key)) {
            throw new Exception("SSH connection failed for server {$server->name} ({$server->ip})");
        }
        
        // Reset timeout pour les longues commandes (builds)
        $this->ssh->setTimeout(0);
    }

    public function exec(string $command, bool $throwOnError = true): string
    {
        if (!$this->ssh) {
            throw new Exception("SSH not connected. Call connect() first.");
        }

        // On redirige stderr vers stdout pour toujours récupérer les erreurs système bas niveau proprement 
        $output = $this->ssh->exec($command . ' 2>&1');
        
        if ($throwOnError && $this->ssh->getExitStatus() !== 0) {
           throw new Exception("Command failed: {$command}\nOutput: {$output}");
        }

        return $output ?: '';
    }

    public function stream(string $command, callable $onLine): void
    {
        if (!$this->ssh) {
            throw new Exception("SSH not connected. Call connect() first.");
        }

        // Buffer pour reconstruire les lignes proprement (car SSH n'envoie que des chunks)
        $buffer = '';

        $this->ssh->exec($command . ' 2>&1', function ($str) use ($onLine, &$buffer) {
            $buffer .= $str;
            
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                
                $line = trim($line);
                if (!empty($line)) {
                    $onLine($line);
                }
            }
        });
        
        // S'il reste du texte sans saut de ligne à la fin du flux
        $buffer = trim($buffer);
        if (!empty($buffer)) {
            $onLine($buffer);
        }
        
        // Tolérance d'erreur si l'exit status est non-zéro
        if ($this->ssh->getExitStatus() !== 0) {
            throw new Exception("Command stream failed with exit status " . $this->ssh->getExitStatus() . ": {$command}");
        }
    }

    public function upload(Server $server, string $localPath, string $remotePath): void
    {
        $sftp = new SFTP($server->ip, $server->ssh_port);
        $key = PublicKeyLoader::load($server->ssh_private_key);

        if (!$sftp->login($server->ssh_user, $key)) {
            throw new Exception("SFTP connection failed for server {$server->name}");
        }

        if (!$sftp->put($remotePath, $localPath, SFTP::SOURCE_LOCAL_FILE)) {
            throw new Exception("Failed to upload file to {$remotePath}");
        }
    }
}
