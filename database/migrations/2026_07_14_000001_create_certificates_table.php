<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('description', 250);
            $table->string('reference', 15)->nullable();
            $table->string('pfx_path');
            $table->text('password'); // cast encrypted no model
            $table->string('sign_image_path')->nullable();
            $table->string('logo_image_path')->nullable();
            $table->date('expires_at')->nullable(); // validTo do X.509, extraído no upload
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::drop('certificates');
    }
};
