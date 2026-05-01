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
        Schema::rename('standalone_postgresqls', 'standalone_databases');

        Schema::table('standalone_databases', function (Blueprint $table) {
            $table->renameColumn('postgres_user', 'db_user');
            $table->renameColumn('postgres_password', 'db_password');
            $table->renameColumn('postgres_db', 'db_name');
            $table->string('type')->default('postgres')->after('uuid');
            $table->boolean('has_adminer')->default(false)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('standalone_databases', function (Blueprint $table) {
            $table->renameColumn('db_user', 'postgres_user');
            $table->renameColumn('db_password', 'postgres_password');
            $table->renameColumn('db_name', 'postgres_db');
            $table->dropColumn(['type', 'has_adminer']);
        });

        Schema::rename('standalone_databases', 'standalone_postgresqls');
    }
};
