<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_status_flows', function (Blueprint $table) {
            $table->id();

            $table->foreignId('service_id')
                ->constrained('services')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            $table->foreignId('status_id')
                ->constrained('statuses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->unsignedInteger('step_order'); // 1,2,3...

            $table->timestamps();

            $table->unique(['service_id', 'status_id']);
            $table->unique(['service_id', 'step_order']);
            $table->index(['status_id', 'step_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_status_flows');
    }
};
