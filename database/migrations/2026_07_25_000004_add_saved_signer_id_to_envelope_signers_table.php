<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelope_signers', function (Blueprint $table) {
            $table->foreignId('saved_signer_id')->nullable()->after('envelope_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('envelope_signers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('saved_signer_id');
        });
    }
};
