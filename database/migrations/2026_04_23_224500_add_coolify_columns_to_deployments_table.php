<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->uuid('deployment_uuid')->nullable()->after('id');
            $table->longText('logs')->nullable()->after('status');
            $table->string('commit')->nullable()->after('logs');
            $table->string('commit_message')->nullable()->after('commit');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn(['deployment_uuid', 'logs', 'commit', 'commit_message']);
        });
    }
};
