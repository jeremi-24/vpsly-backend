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
        Schema::table('applications', function (Blueprint $col) {
            $col->string('deployment_mode')->default('docker')->after('id');
            $col->string('target_path')->nullable()->after('domain');
            $col->text('deploy_script')->nullable()->after('target_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $col) {
            $col->dropColumn(['deployment_mode', 'target_path', 'deploy_script']);
        });
    }
};
