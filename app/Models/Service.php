<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class Service extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'service_order',
        'client',
        'cylinder_head',
        'description',
        'current_status_id',
        'paid',
        'in_progress',
        'completed_at',
    ];

    protected $casts = [
        'paid'         => 'boolean',
        'in_progress'  => 'boolean',
        'completed_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */
    public function currentStatus()
    {
        return $this->belongsTo(Status::class, 'current_status_id');
    }

    public function flow()
    {
        return $this->hasMany(ServiceStatusFlow::class)->orderBy('step_order');
    }

    public function logs()
    {
        return $this->hasMany(ServiceStatusLog::class)->latest();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Limita os serviços aos status que o usuário pode ver (Spatie).
     * Suporta permissões em dois formatos:
     *  - services.view.status.<slug>   (ex.: retifica, montagem)
     *  - services.view.status.<id>     (ex.: 9, 10)
     */
    public function scopeVisibleTo(Builder $query, \App\Models\User $user): Builder
    {
        if ($user->hasRole('admin')) {
            return $query; // admin vê tudo
        }

        // coleta o sufixo das permissões do tipo services.view.status.*
        $perms = $user->getAllPermissions()
            ->pluck('name')
            ->filter(fn($p) => str_starts_with($p, 'services.view.status.'))
            ->map(fn($p) => Str::after($p, 'services.view.status.'))
            ->values();

        // separa ids e slugs
        $ids   = $perms->filter(fn($v) => is_numeric($v))->map(fn($v) => (int) $v)->all();
        $slugs = $perms->reject(fn($v) => is_numeric($v))->all();

        if (!empty($slugs)) {
            $idsFromSlugs = Status::query()
                ->whereIn('name', []) // força 0=1 se vazio
                ->pluck('id');

            // converte slugs para ids comparando com o accessor slug (name slugificado)
            // SELECT id FROM statuses WHERE LOWER(REPLACE(name,' ', '-')) IN ($slugs)
            $idsFromSlugs = Status::query()
                ->whereInRaw("LOWER(REPLACE(name, ' ', '-'))", array_map(fn($s) => strtolower($s), $slugs))
                ->pluck('id');

            $ids = array_values(array_unique(array_merge($ids, $idsFromSlugs->all())));
        }

        // se nada permitido, retorna vazio
        if (empty($ids)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('current_status_id', $ids);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers de fluxo (opcionais)
    |--------------------------------------------------------------------------
    */

    public function start(\App\Models\User $user): void
    {
        if ($this->flow_locked || $this->isTerminal()) {
            throw new \RuntimeException('Serviço finalizado: não é possível iniciar.');
        }

        $this->logs()->firstOrCreate(
            ['status_id' => $this->current_status_id, 'finished_at' => null],
            ['user_id' => $user->id, 'started_at' => now()]
        );

        // marca como em execução
        $this->updateQuietly(['in_progress' => true]);
    }

    public function isTerminal(): bool
    {
        return (bool) optional($this->currentStatus)->is_terminal;
    }

    public function lockFlow(): void
    {
        $this->updateQuietly(['flow_locked' => true]);
    }

    /**
     * Finaliza tudo e envia para um status terminal.
     */
    public function finalizeToTerminal(\App\Models\User $user, ?int $terminalStatusId = null): void
    {
        // escolhe um terminal (primeiro da lista) se não vier ID
        $terminalStatusId = $terminalStatusId
            ?? \App\Models\Status::query()->where('is_terminal', true)->value('id');

        if (!$terminalStatusId) {
            throw new \RuntimeException('Nenhum status terminal configurado.');
        }

        // 1) Finaliza qualquer log em aberto da etapa atual
        $this->finish($user, /*advanceToNext=*/ false);

        // 2) Marca todos do fluxo como concluídos (fecha logs em aberto)
        $flowIds = $this->flow()->orderBy('step_order')->pluck('status_id')->all();
        if (!empty($flowIds)) {
            $now = now();
            $this->logs()
                ->whereIn('status_id', $flowIds)
                ->whereNull('finished_at')
                ->reorder() // limpa qualquer ORDER BY herdado
                ->update(['finished_at' => $now, 'finished_by' => $user->id]);
        }

        // 3) Vai para o terminal, data concluída e trava
        $this->update([
            'current_status_id' => (int) $terminalStatusId,
            'completed_at'      => now(),
            'flow_locked'       => true,
        ]);
    }

    /**
     * Sobrecarga para terminar etapa atual; se for a última do fluxo, pula para terminal.
     * Acrescente o parâmetro opcional para reaproveitar no finalizeToTerminal.
     */
    // App/Models/Service.php

    public function finish(\App\Models\User $user, bool $advanceToNext = true): void
    {
        if ($this->flow_locked || $this->isTerminal()) {
            throw new \RuntimeException('Serviço finalizado: não é possível finalizar.');
        }

        $now = now();

        // 1) Fecha o log em aberto da etapa atual (se houver)
        $open = $this->logs()
            ->where('status_id', $this->current_status_id)
            ->whereNull('finished_at');

        // se você criou a coluna finished_by, mantenha a linha com finished_by; caso não tenha, remova-a
        $open->reorder()->update([
            'finished_at' => $now,
            'finished_by' => $user->id, // remova esta linha se sua tabela não tiver a coluna
        ]);

        // 2) Seta como NÃO em execução
        $this->updateQuietly(['in_progress' => false]);

        // 3) Se não for para avançar, para aqui (usado pelo finalizeToTerminal)
        if (!$advanceToNext) {
            return;
        }

        // 4) Determina o próximo status na trilha
        $flowIds = $this->flow()->orderBy('step_order')->pluck('status_id')->values()->all();

        if (empty($flowIds)) {
            // Sem trilha definida — nada a avançar. Apenas sai.
            return;
        }

        // Se o current_status_id não estiver na trilha (alterações manuais), reposiciona para o primeiro
        $currentIndex = array_search((int) $this->current_status_id, array_map('intval', $flowIds), true);

        if ($currentIndex === false) {
            // Coloca no primeiro da trilha
            $this->updateQuietly([
                'current_status_id' => (int) $flowIds[0],
                'completed_at'      => null,
            ]);
            return;
        }

        // Se já está no último da trilha, vai para o terminal
        if ($currentIndex === count($flowIds) - 1) {
            $this->finalizeToTerminal($user);
            return;
        }

        // 5) Avança para o próximo status da trilha e garante que não está concluído
        $nextId = (int) $flowIds[$currentIndex + 1];
        $this->update([
            'current_status_id' => $nextId,
            'completed_at'      => null,
        ]);
    }

    protected static function booted()
    {
        static::created(function ($service) {
            $service->updateQuietly(['service_order' => $service->id]);
        });
    }
}
