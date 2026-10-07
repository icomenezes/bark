<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelopes', function (Blueprint $table) {
            // Por onde o envelope foi criado (web | api). Só os da API disparam webhook.
            // Envelopes anteriores ficam 'web': não há como saber quais vieram da API.
            $table->string('source', 10)->default('web')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('envelopes', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
