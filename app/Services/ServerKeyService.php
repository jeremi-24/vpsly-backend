<?php

namespace App\Services;

use phpseclib3\Crypt\RSA;

class ServerKeyService
{
    /**
     * Génère une nouvelle paire de clés dynamique pour un VPS.
     * Retourne la clé privée et la clé publique sous forme de string.
     */
    public function generateKeyPair(): array
    {
        $key = RSA::createKey(4096);
        
        return [
            'private_key' => $key->toString('OpenSSH'),
            'public_key'  => $key->getPublicKey()->toString('OpenSSH')
        ];
    }

    /**
     * Fournit la ligne de commande exacte que l'utilisateur doit coller
     * sur son serveur cible fraîchement acheté.
     */
    public function getInstallCommand(string $publicKey): string
    {
        return sprintf(
            'mkdir -p ~/.ssh && chmod 700 ~/.ssh && echo "%s" >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys',
            trim($publicKey)
        );
    }
}
