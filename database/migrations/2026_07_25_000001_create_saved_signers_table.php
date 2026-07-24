<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_signers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('channel', ['email', 'whatsapp'])->default('email');
            $table->string('email')->nullable();
            $table->string('whatsapp')->nullable();
            $table->enum('auth_method', ['link', 'email_otp', 'whatsapp_otp'])->default('link');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_signers');
    }
};
