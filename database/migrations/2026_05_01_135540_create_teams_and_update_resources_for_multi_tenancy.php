<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Création de la table Teams
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('owner_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });

        // 2. Table Pivot Team User (Collaborateurs)
        Schema::create('team_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('role')->default('member'); // owner, admin, member
            $table->timestamps();
        });

        // 3. Mise à jour de la table Users pour l'équipe courante
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_team_id')->nullable()->constrained('teams')->onDelete('set null');
        });

        // 4. Ajout de team_id sur les ressources
        $tables = [
            'servers',
            'applications',
            'standalone_databases',
            'backups',
            'local_persistent_volumes',
            'environment_variables',
            'scheduled_tasks',
        ];

        foreach ($tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('team_id')->nullable()->constrained()->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'scheduled_tasks',
            'environment_variables',
            'local_persistent_volumes',
            'backups',
            'standalone_databases',
            'applications',
            'servers',
        ];

        foreach ($tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign([ 'team_id' ]);
                $table->dropColumn('team_id');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign([ 'current_team_id' ]);
            $table->dropColumn('current_team_id');
        });

        Schema::dropIfExists('team_user');
        Schema::dropIfExists('teams');
    }
};
