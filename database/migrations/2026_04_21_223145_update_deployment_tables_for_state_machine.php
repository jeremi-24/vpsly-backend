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
        Schema::table('applications', function (Blueprint $table) {
            // Mise à jour de la colonne status pour supporter les nouveaux états
            $table->string('status')->default('pending')->change();
        });

        Schema::table('deployments', function (Blueprint $table) {
            $table->string('status')->default('pending')->change();
        });

        Schema::table('deployment_logs', function (Blueprint $table) {
            $table->string('type')->default('info')->after('line'); // info, success, error, debug
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deployment_logs', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
