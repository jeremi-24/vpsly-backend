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
            $table->boolean('has_laravel_scheduler')->default(false);
            $table->timestamp('last_cron_synced_at')->nullable();
            $table->text('last_cron_sync_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn(['has_laravel_scheduler', 'last_cron_synced_at', 'last_cron_sync_error']);
        });
    }
};
