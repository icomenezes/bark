<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('original_pdf_path');
            $table->string('final_pdf_path')->nullable();
            $table->string('sha256_original', 64);
            $table->string('sha256_final', 64)->nullable();
            $table->enum('signing_order', ['parallel', 'sequential'])->default('parallel');
            $table->enum('status', ['draft', 'sent', 'completed', 'declined', 'cancelled', 'expired'])->default('draft');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envelopes');
    }
};
