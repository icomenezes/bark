<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelope_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_id')->constrained()->cascadeOnDelete();
            $table->foreignId('envelope_signer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 50);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envelope_events');
    }
};
