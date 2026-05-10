<?php

namespace App\Services\Deployment;

use App\Models\Application;
use Illuminate\Support\Facades\Log;
use Exception;

class LegacyProvisionerService
{
    public function __construct(protected SSHService $ssh)
    {
    }

    /**
     * Détecte la stack technologique à l'aide de Nixpacks ou scan de fichiers.
     */
    public function detectStack(Application $app, string $path): string
    {
        $pathEscaped = escapeshellarg($path);

        // Scan des fichiers prioritaire (plus fiable et rapide que Nixpacks)
        $files = $this->ssh->exec("ls {$pathEscaped}");

        if (str_contains($files, 'artisan')) return 'php';
        if (str_contains($files, 'next.config')) return 'node';
        if (str_contains($files, 'nuxt.config')) return 'node';
        if (str_contains($files, 'package.json')) return 'node';
        if (str_contains($files, 'requirements.txt')) return 'python';
        if (str_contains($files, 'go.mod')) return 'go';
        if (str_contains($files, 'composer.json')) return 'php';

        // Fallback Nixpacks
        $this->ensureNixpacksInstalled();
        $output = $this->ssh->exec("nixpacks plan {$pathEscaped} --format json");
        $plan = json_decode($output, true);
        $providers = $plan['providers'] ?? [];

        if (in_array('php', $providers)) return 'php';
        if (in_array('node', $providers)) return 'node';
        if (in_array('python', $providers)) return 'python';
        if (in_array('go', $providers)) return 'go';

        return 'generic';
    }

    /**
     * Provisionne Nginx et Certbot pour l'application.
     */
    public function provisionWebserver(Application $app, string $stack): void
    {
        $sanitizedAppName = strtolower(str_replace('_', '-', $app->name));
        $domain = $app->domain ?: "{$sanitizedAppName}.{$app->server->ip}.sslip.io";
        $enabledPath = "/etc/nginx/sites-enabled/" . str_replace('.', '_', $domain) . ".vpsly.conf";

        // 1. Génération du template
        $config = $this->generateNginxConfig($app, $stack, $domain);

        // 2. Upload et Activation
        $safeName = str_replace('.', '_', $domain);
        $remotePath = "/etc/nginx/sites-available/{$safeName}.vpsly.conf";

        $tmpPath = "/tmp/{$safeName}.vpsly.conf";
        $this->ssh->upload($tmpPath, $config);

        $this->ssh->exec("sudo mv {$tmpPath} {$remotePath}");
        $this->ssh->exec("sudo ln -sf {$remotePath} {$enabledPath}");

        // 3. Reload Nginx
        $this->ssh->exec("sudo nginx -t && sudo systemctl reload nginx");

        // 4. SSL via Certbot (Si DNS OK et pas local)
        if ($app->domain) {
            $this->provisionSsl($app, $domain);
        }
    }

    /**
     * Vérifie si l'application répond localement sur son port par défaut.
     */
    public function localHealthCheck(Application $app, string $stack): bool
    {
        $port = $this->getStackDefaultPort($stack);
        $output = $this->ssh->exec("curl -s -o /dev/null -w '%{http_code}' http://localhost:{$port}");

        $code = (int) trim($output);
        // On accepte uniquement 200/201 en local pour éviter les faux-positifs de redirection
        return in_array($code, [200, 201]);
    }

    /**
     * Vérifie si l'application est accessible publiquement via le domaine.
     */
    public function finalHealthCheck(Application $app): bool
    {
        $domain = $app->domain ?: "{$app->name}.{$app->server->ip}.sslip.io";
        $url = "http://{$domain}"; // On commence par HTTP pour éviter les boucles si SSL fail

        $output = $this->ssh->exec("curl -s -I -L -o /dev/null -w '%{http_code}' " . escapeshellarg($url));
        $code = (int) trim($output);

        if ($code >= 200 && $code < 400) {
            Log::info("[Provisioner] Final health check SUCCESS for {$domain} (Code: {$code})");
            return true;
        }

        Log::warning("[Provisioner] Final health check WARNING for {$domain} (Code: {$code})");
        return false;
    }

    protected function ensureNixpacksInstalled(): void
    {
        $check = $this->ssh->exec("which nixpacks || echo 'not_found'");
        if (str_contains($check, 'not_found')) {
            Log::info("[Provisioner] Installing Nixpacks on server...");
            $this->ssh->exec("curl -sSL https://nixpacks.com/install.sh | bash");
        }
    }

    protected function generateNginxConfig(Application $app, string $stack, string $domain): string
    {
        $root = $app->target_path;

        if ($stack === 'php') {
            $phpVersion = $this->detectPhpVersion();
            $socket = "/var/run/php/php{$phpVersion}-fpm.sock";

            return view('templates.nginx.php', [
                'domain' => $domain,
                'root' => $root,
                'socket' => $socket
            ])->render();
        }

        $port = $this->getStackDefaultPort($stack);
        return view('templates.nginx.proxy', [
            'domain' => $domain,
            'port' => $port
        ])->render();
    }

    protected function provisionSSL(Application $app, string $domain): void
    {
        // 1. Est-ce un domaine sslip.io ? (Auto-valide)
        $isSslip = str_contains($domain, 'sslip.io');

        // 2. Sinon, vérifier la résolution DNS
        if (!$isSslip) {
            $serverIp = $app->server->ip;
            $resolvedIp = gethostbyname($domain);

            if ($resolvedIp !== $serverIp) {
                Log::warning("[Provisioner] DNS not propagated for {$domain}. Skipping SSL.");
                return;
            }
        }

        // 3. Lancer Certbot
        Log::info("[Provisioner] Running Certbot for {$domain}");
        $email = "hello@vpsly.tech"; // À dynamiser plus tard
        $this->ssh->exec("sudo certbot --nginx -d {$domain} --non-interactive --agree-tos -m {$email} 2>/dev/null || true");
    }

    protected function detectPhpVersion(): string
    {
        $output = $this->ssh->exec("php -r \"echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;\"");
        return trim($output) ?: '8.3';
    }

    protected function getStackDefaultPort(string $stack): int
    {
        return match ($stack) {
            'node' => 3000,
            'python' => 8000,
            'go' => 8080,
            default => 3000
        };
    }
}
