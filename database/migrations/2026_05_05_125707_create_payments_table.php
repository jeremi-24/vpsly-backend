<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Table des paiements pour l'historique
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->onDelete('cascade');
            $table->string('plan');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('XOF');
            $table->string('reference')->unique();
            $table->string('status')->default('completed'); // GeniusPay success
            $table->string('method')->nullable(); // moov, orange, etc.
            $table->timestamp('paid_at');
            $table->timestamps();
        });

        // Ajout de la date d'expiration sur l'équipe
        Schema::table('teams', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('last_payment_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
