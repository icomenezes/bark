<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uma linha por tentativa de envio. Fica fora de envelope_events de propósito:
        // aquela trilha vai inteira para o certificado de evidências.
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_id')->nullable()->constrained()->nullOnDelete(); // null = envio de teste
            $table->uuid('event_id')->index(); // o mesmo em todas as tentativas da notificação
            $table->string('event', 50);
            $table->string('url', 2048);
            $table->unsignedTinyInteger('attempt');
            $table->unsignedSmallInteger('response_status')->nullable(); // null = sem resposta (timeout, conexão)
            $table->string('error')->nullable(); // nunca o corpo da resposta
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
    }
};
