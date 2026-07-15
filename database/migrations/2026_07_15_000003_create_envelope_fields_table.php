<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envelope_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_signer_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('page');
            $table->decimal('x', 8, 2);
            $table->decimal('y', 8, 2);
            $table->decimal('w', 8, 2);
            $table->decimal('h', 8, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('envelope_fields');
    }
};
