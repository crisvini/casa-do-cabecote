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
        'completed_at' => 'date',
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
        $this->logs()->firstOrCreate(
            ['status_id' => $this->current_status_id, 'finished_at' => null],
            ['user_id' => $user->id, 'started_at' => now()]
        );

        // marca como em execução
        $this->updateQuietly(['in_progress' => true]);
    }

    public function finish(\App\Models\User $user): void
    {
        // fecha o log em aberto da etapa atual
        $log = $this->logs()
            ->where('status_id', $this->current_status_id)
            ->whereNull('finished_at')
            ->latest()
            ->first();

        if ($log) {
            $log->update(['finished_at' => now(), 'user_id' => $user->id]);
        }

        // 1) Descobre a ordem (step_order) da etapa atual dentro do flow deste serviço
        $currentOrder = $this->flow()
            ->where('status_id', $this->current_status_id)
            ->value('step_order'); // int|null

        // Se não encontrou a etapa atual no flow, só marca como não-executando e sai
        if ($currentOrder === null) {
            $this->updateQuietly(['in_progress' => false]);
            return;
        }

        // 2) Busca a próxima etapa do flow (maior step_order)
        $next = $this->flow()
            ->where('step_order', '>', $currentOrder)
            ->orderBy('step_order')
            ->first();

        if ($next) {
            // Avança para a próxima etapa; fica parado até alguém iniciar a próxima
            $this->updateQuietly([
                'current_status_id' => (int) $next->status_id,
                'in_progress'       => false,
                'completed_at'      => null,
            ]);
        } else {
            // Não há próxima etapa: serviço concluído
            $this->updateQuietly([
                'completed_at' => now(),
                'in_progress'  => false,
            ]);
        }
    }

    protected static function booted()
    {
        static::created(function ($service) {
            $service->updateQuietly(['service_order' => $service->id]);
        });
    }
}
