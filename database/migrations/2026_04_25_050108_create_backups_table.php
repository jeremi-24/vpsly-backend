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
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->onDelete('cascade');
            $table->foreignId('database_id')->nullable()->constrained('standalone_postgresqls')->onDelete('set null');
            $table->string('name'); // Nom du fichier
            $table->string('type'); // db, volume
            $table->string('status')->default('pending'); // pending, success, failed
            $table->unsignedBigInteger('size')->default(0); // Taille en bytes
            $table->string('path')->nullable(); // Chemin absolu sur le VPS
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
