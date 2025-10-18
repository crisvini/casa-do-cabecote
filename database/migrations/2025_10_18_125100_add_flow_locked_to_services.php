<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->boolean('flow_locked')->default(false)->after('completed_at'); // bloqueia start/finish e edição do fluxo/ordem
        });
    }
    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('flow_locked');
        });
    }
};
