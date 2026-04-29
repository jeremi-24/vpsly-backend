<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_backup_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->onDelete('cascade');
            
            $table->boolean('is_enabled')->default(true);
            $table->string('frequency_override')->nullable();
            $table->integer('retention_override')->nullable();
            $table->json('excluded_paths')->nullable(); // Dossiers à ignorer
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_backup_overrides');
    }
};
