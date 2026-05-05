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
        Schema::table('teams', function (Blueprint $table) {
            $table->string('subscription_status')->default('active')->after('plan');
            $table->timestamp('last_payment_at')->nullable()->after('subscription_status');
            $table->string('payment_reference')->nullable()->after('last_payment_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['subscription_status', 'last_payment_at', 'payment_reference']);
        });
    }
};
