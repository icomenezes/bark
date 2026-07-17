<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelope_signers', function (Blueprint $table) {
            $table->enum('channel', ['email', 'whatsapp'])->default('email')->after('cpf');
        });
    }

    public function down(): void
    {
        Schema::table('envelope_signers', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
