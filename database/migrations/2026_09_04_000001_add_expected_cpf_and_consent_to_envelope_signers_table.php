<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelope_signers', function (Blueprint $table) {
            // CPF que o remetente espera do signatário; null = sem conferência.
            $table->string('expected_cpf', 14)->nullable()->after('cpf');
            $table->timestamp('consent_accepted_at')->nullable()->after('expected_cpf');
            $table->string('consent_version', 10)->nullable()->after('consent_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::table('envelope_signers', function (Blueprint $table) {
            $table->dropColumn(['expected_cpf', 'consent_accepted_at', 'consent_version']);
        });
    }
};
