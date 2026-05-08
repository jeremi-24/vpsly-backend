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
            $table->string('healthcheck_path')->default('/');
            $table->string('healthcheck_status_codes')->default('200,301,302,304,401,404,405');
            $table->boolean('ignore_healthcheck_warnings')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['healthcheck_path', 'healthcheck_status_codes', 'ignore_healthcheck_warnings']);
        });
    }
};
