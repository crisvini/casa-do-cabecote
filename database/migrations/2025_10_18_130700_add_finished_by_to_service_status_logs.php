<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_status_logs', function (Blueprint $table) {
            $table->foreignId('finished_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete()
                ->after('finished_at');
        });
    }
    public function down(): void
    {
        Schema::table('service_status_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('finished_by');
        });
    }
};
