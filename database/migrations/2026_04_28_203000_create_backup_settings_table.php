<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // QUAND
            $table->string('frequency')->default('daily'); // hourly, daily, weekly, manual
            $table->time('execution_time')->default('02:00');
            $table->integer('retention_days')->default(7);
            
            // OÙ
            $table->string('storage_destination')->default('local'); // local, google_drive, s3
            $table->text('storage_credentials')->nullable(); // Sera casté en 'encrypted' dans le Model
            
            // ALERTE
            $table->string('notification_channel')->default('email'); // email, whatsapp, both
            $table->string('notification_phone')->nullable();
            $table->string('notification_email')->nullable();
            
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_settings');
    }
};
