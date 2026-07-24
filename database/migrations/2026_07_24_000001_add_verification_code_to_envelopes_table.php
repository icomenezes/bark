<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('envelopes', function (Blueprint $table) {
            $table->string('verification_code', 36)->nullable()->after('id');
        });

        DB::table('envelopes')->whereNull('verification_code')->orderBy('id')->each(function ($row) {
            DB::table('envelopes')->where('id', $row->id)->update(['verification_code' => Str::uuid()->toString()]);
        });

        Schema::table('envelopes', function (Blueprint $table) {
            $table->string('verification_code', 36)->nullable(false)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('envelopes', function (Blueprint $table) {
            $table->dropUnique(['verification_code']);
            $table->dropColumn('verification_code');
        });
    }
};
