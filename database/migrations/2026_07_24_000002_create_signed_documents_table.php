<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signed_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('certificate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('verification_code', 36)->unique();
            $table->string('title');
            $table->string('sha256', 64);
            $table->timestamp('signed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signed_documents');
    }
};
