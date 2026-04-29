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
        Schema::table('application_backup_overrides', function (Blueprint $table) {
            if (Schema::hasColumn('application_backup_overrides', 'retention_override')) {
                $table->dropColumn('retention_override');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('application_backup_overrides', function (Blueprint $table) {
            $table->integer('retention_override')->nullable();
        });
    }
};
