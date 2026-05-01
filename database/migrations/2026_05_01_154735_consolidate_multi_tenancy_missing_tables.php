<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'deployment_logs',
            'application_backup_overrides',
        ];

        foreach ($tables as $tableName) {
            if (Schema::hasTable($tableName)) {
                Schema::table($tableName, function (Blueprint $table) {
                    if (!Schema::hasColumn($table->getTable(), 'team_id')) {
                        $table->foreignId('team_id')->nullable()->constrained()->onDelete('cascade');
                    }
                });
            }
        }

        // Special case for backup_settings: migrate user_id to team_id if needed
        if (Schema::hasTable('backup_settings')) {
            Schema::table('backup_settings', function (Blueprint $table) {
                if (!Schema::hasColumn('backup_settings', 'team_id')) {
                    $table->foreignId('team_id')->nullable()->constrained()->onDelete('cascade');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('backup_settings', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropColumn('team_id');
        });

        $tables = [
            'application_backup_overrides',
            'deployment_logs',
        ];

        foreach ($tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['team_id']);
                $table->dropColumn('team_id');
            });
        }
    }
};
