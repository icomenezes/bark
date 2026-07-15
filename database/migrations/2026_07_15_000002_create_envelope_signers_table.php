<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelope_signers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('whatsapp')->nullable();
            $table->string('cpf', 14)->nullable();
            $table->enum('auth_method', ['link', 'email_otp', 'whatsapp_otp'])->default('link');
            $table->unsignedInteger('sign_position')->default(1);
            $table->string('token', 64)->unique();
            $table->enum('status', ['pending', 'notified', 'viewed', 'signed', 'declined'])->default('pending');
            $table->string('signature_image_path')->nullable();
            $table->enum('signature_type', ['drawn', 'typed'])->nullable();
            $table->string('otp_code')->nullable();
            $table->timestamp('otp_expires_at')->nullable();
            $table->unsignedTinyInteger('otp_attempts')->default(0);
            $table->timestamp('signed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envelope_signers');
    }
};
