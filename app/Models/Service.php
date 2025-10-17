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
        'completed_at',
    ];

    protected $casts = [
        'paid'         => 'boolean',
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

    /** Inicia o status atual: cria log de start se não existir. */
    public function start(\App\Models\User $user): void
    {
        $this->logs()->firstOrCreate(
            ['status_id' => $this->current_status_id, 'finished_at' => null],
            ['user_id' => $user->id, 'started_at' => now()]
        );
    }

    /** Finaliza o status atual, seta finished_at; se for o último do flow, marca completed_at. */
    public function finish(\App\Models\User $user): void
    {
        $log = $this->logs()
            ->where('status_id', $this->current_status_id)
            ->whereNull('finished_at')
            ->latest()
            ->first();

        if ($log) {
            $log->update(['finished_at' => now(), 'user_id' => $user->id]);
        }

        // se tiver um próximo status no flow, avance; senão, conclua
        $next = $this->flow()->where('step_order', '>', function ($q) {
            $q->from('service_status_flows')
                ->select('step_order')
                ->whereColumn('service_status_flows.service_id', 'services.id')
                ->whereColumn('service_status_flows.status_id', 'services.current_status_id')
                ->limit(1);
        })->orderBy('step_order')->first();

        if ($next) {
            $this->update(['current_status_id' => $next->status_id]);
        } else {
            $this->update(['completed_at' => now()]);
        }
    }

    protected static function booted()
    {
        static::created(function ($service) {
            $service->updateQuietly(['service_order' => $service->id]);
        });
    }
}
