<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_name')->default('Sistema Base');
            $table->string('logo_url')->nullable();
            $table->string('favicon_url')->nullable();
            $table->string('primary_color', 7)->default('#1e40af');
            $table->string('accent_color', 7)->default('#3b82f6');
            $table->string('support_email')->nullable();
            $table->string('support_whatsapp')->nullable();
            $table->boolean('whatsapp_enabled')->default(false);
            $table->timestamps();
        });

        // Insere configuração padrão
        DB::table('settings')->insert([
            'company_name'  => config('app.name', 'Sistema Base'),
            'primary_color' => '#1e40af',
            'accent_color'  => '#3b82f6',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
