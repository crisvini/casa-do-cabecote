<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // muda o tipo de date -> datetime
            $table->dateTime('completed_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // reverte para date caso precise voltar
            $table->date('completed_at')->nullable()->change();
        });
    }
};
