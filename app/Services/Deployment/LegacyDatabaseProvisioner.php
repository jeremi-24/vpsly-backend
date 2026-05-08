<?php

namespace App\Services\Deployment;

use App\Models\StandaloneDatabase;
use Exception;
use Illuminate\Support\Facades\Log;

class LegacyDatabaseProvisioner
{
    public function __construct(
        protected SSHService $ssh
    ) {}

    /**
     * Déploie ou met à jour une base de données sur le système hôte (Legacy).
     */
    public function provision(StandaloneDatabase $database): void
    {
        $server = $database->server;
        $type = $database->type;

        Log::info("[LegacyDatabase] Provisioning {$database->name} ({$type}) on Host: {$server->ip}");

        try {
            $this->ssh->connect($server);

            if ($type === 'mysql' || $type === 'mariadb') {
                $this->provisionMysql($database);
            } elseif ($type === 'postgres') {
                $this->provisionPostgres($database);
            } elseif ($type === 'redis') {
                $this->provisionRedis($database);
            } else {
                throw new Exception("Type de base de données non supporté en mode Legacy : {$type}");
            }

            $database->update([
                'status' => 'running',
                'started_at' => now()
            ]);

            Log::info("[LegacyDatabase] Successfully provisioned {$database->name}");

        } catch (Exception $e) {
            Log::error("[LegacyDatabase] Provisioning failed: " . $e->getMessage());
            $database->update(['status' => 'failed']);
            throw $e;
        } finally {
            $this->ssh->disconnect();
        }
    }

    /**
     * Provisionne MySQL ou MariaDB au niveau système.
     */
    protected function provisionMysql(StandaloneDatabase $database): void
    {
        $dbName = str_replace('`', '``', $database->db_name);
        $dbUser = $database->db_user;
        $dbPassword = $database->db_password; // Passwords in SQL file are safe from shell evaluation

        $sql = "CREATE DATABASE IF NOT EXISTS `{$dbName}`;\n";
        $sql .= "CREATE USER IF NOT EXISTS '{$dbUser}'@'localhost' IDENTIFIED BY '{$dbPassword}';\n";
        $sql .= "GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$dbUser}'@'localhost';\n";
        $sql .= "FLUSH PRIVILEGES;\n";

        $tmpFile = '/tmp/provision_mysql_' . bin2hex(random_bytes(8)) . '.sql';
        $this->ssh->upload($tmpFile, $sql);

        try {
            $this->ssh->exec("sudo mysql < {$tmpFile}");
        } finally {
            $this->ssh->exec("rm -f {$tmpFile}");
        }
    }

    /**
     * Provisionne PostgreSQL au niveau système.
     */
    protected function provisionPostgres(StandaloneDatabase $database): void
    {
        $dbName = str_replace('"', '""', $database->db_name);
        $dbUser = str_replace('"', '""', $database->db_user);
        $dbPassword = str_replace("'", "''", $database->db_password);

        // Note: psql va throw une erreur si la DB ou le User existe déjà, d'où le bloc try/catch ou || true.
        $sql = "DO \$\$\nBEGIN\n";
        $sql .= "  IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = '{$dbUser}') THEN\n";
        $sql .= "    CREATE ROLE \"{$dbUser}\" LOGIN PASSWORD '{$dbPassword}';\n";
        $sql .= "  END IF;\n";
        $sql .= "END\n\$\$;\n";
        
        // PostgreSQL ne permet pas de CREATE DATABASE dans un bloc PL/pgSQL
        // On exécute d'abord la création de l'utilisateur
        $tmpFileUser = '/tmp/provision_pg_user_' . bin2hex(random_bytes(8)) . '.sql';
        $this->ssh->upload($tmpFileUser, $sql);

        try {
            $this->ssh->exec("sudo -u postgres psql -f {$tmpFileUser}");
        } finally {
            $this->ssh->exec("rm -f {$tmpFileUser}");
        }

        // Ensuite, on crée la base de données. En ignorant l'erreur si elle existe déjà.
        $sqlDb = "CREATE DATABASE \"{$dbName}\" OWNER \"{$dbUser}\";\n";
        $tmpFileDb = '/tmp/provision_pg_db_' . bin2hex(random_bytes(8)) . '.sql';
        $this->ssh->upload($tmpFileDb, $sqlDb);

        try {
            $this->ssh->exec("sudo -u postgres psql -f {$tmpFileDb} || true");
        } finally {
            $this->ssh->exec("rm -f {$tmpFileDb}");
        }
    }

    /**
     * Provisionne Redis au niveau système (Vérifie juste si le service tourne).
     */
    protected function provisionRedis(StandaloneDatabase $database): void
    {
        // Redis système n'a pas vraiment de concept de "Base de données isolée" au sens SQL.
        // On vérifie si Redis est installé et on s'assure qu'il tourne.
        $this->ssh->exec("redis-cli ping | grep PONG || sudo systemctl start redis");
    }
}
