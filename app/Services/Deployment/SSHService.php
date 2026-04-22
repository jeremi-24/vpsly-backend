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

        
        // Reset timeout pour les commandes potentiellement longues
        $this->ssh->setTimeout(0);

        return $this;
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

    public function disconnect(): void
    {
        if ($this->ssh) {
            $this->ssh->disconnect();
            $this->ssh = null;
        }
    }
}
