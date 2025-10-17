<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Opcional: limpeza defensiva em dev
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('services');
        Schema::enableForeignKeyConstraints();

        Schema::create('services', function (Blueprint $table) {
            $table->id();

            // Agora é coluna normal; você preencherá depois
            $table->unsignedBigInteger('service_order')->nullable()->unique();

            // Domínio
            $table->string('client');
            $table->string('cylinder_head');
            $table->text('description')->nullable();

            // Status atual (FK para statuses)
            $table->foreignId('current_status_id')
                ->constrained('statuses')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Pagamento e conclusão
            $table->boolean('paid')->default(false);
            $table->date('completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Índices úteis
            $table->index('client');
            $table->index('cylinder_head');
            $table->index('current_status_id');
            $table->index('paid');
            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
