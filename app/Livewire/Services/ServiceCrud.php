<?php

namespace App\Livewire\Services;

use App\Models\Service;
use App\Models\Status;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;

#[Layout('components.layouts.app')]
class ServiceCrud extends Component
{
    use WithPagination;

    // filtros
    public string $search = '';
    public ?int $statusFilter = null;
    public ?string $paidFilter = null;

    // form (create/edit)
    public ?int $editingId = null;
    public ?int $current_status_id = null;
    public array $flow_status_ids = [];
    public array $flow_order_ids = [];
    public ?string $client = '';
    public ?string $cylinder_head = '';
    public ?string $description = '';
    public ?string $completed_at = null;
    public bool $paid = false;
    public ?int $service_order = null;

    public bool $showForm = false;
    public bool $confirmingDelete = false;
    public bool $confirmingToggle = false;
    public ?int $toggleId = null;
    public string $confirmAction = 'start'; // 'start' | 'finish'
    public string $confirmMessage = '';
    public bool $editingLocked = false;
    public bool $isViewing = false;

    public function rules(): array
    {
        return [
            'client'            => ['required', 'string', 'max:255'],
            'cylinder_head'     => ['required', 'string', 'max:255'],
            'description'       => ['nullable', 'string'],
            'current_status_id' => ['required', Rule::exists('statuses', 'id')],
            // Somente itens que NÃO são terminais e são selecionáveis podem ir ao fluxo
            'flow_status_ids'   => ['required', 'array', 'min:1'],
            'flow_status_ids.*' => [
                'integer',
                Rule::exists('statuses', 'id')->where(
                    fn($q) => $q
                        ->where('is_terminal', false)
                        ->where('is_selectable', true)
                ),
            ],
            'paid'              => ['boolean'],
            'completed_at'      => ['nullable', 'date'],
            'service_order'     => ['nullable', 'integer', 'min:1', Rule::unique('services', 'service_order')->ignore($this->editingId)],
        ];
    }

    public function mount(): void
    {
        // default para novo
        $this->current_status_id = Status::query()->value('id'); // primeiro status
    }

    public function updatingSearch()
    {
        $this->resetPage();
    }
    public function updatingStatusFilter()
    {
        $this->resetPage();
    }
    public function updatingPaidFilter()
    {
        $this->resetPage();
    }

    public function openCreate(): void
    {
        abort_unless(auth()->user()->can('services.manage'), 403);
        $this->resetForm();

        $first = Status::query()->orderBy('id')->limit(1)->pluck('id')->all();
        $this->flow_status_ids = array_map('intval', $first);
        $this->flow_order_ids  = array_map('intval', $first);
        $this->paid = false;

        $this->editingLocked = false;
        $this->isViewing = false;
        $this->showForm = true;
    }

    public function openEdit(int $id): void
    {
        abort_unless(auth()->user()->can('services.manage'), 403);
        $service = Service::with('flow')->findOrFail($id);

        $this->fill([
            'editingId'         => $service->id,
            'client'            => $service->client,
            'cylinder_head'     => $service->cylinder_head,
            'description'       => $service->description,
            'current_status_id' => $service->current_status_id,
            'paid'              => (bool) $service->paid,
            'completed_at'      => optional($service->completed_at)->format('d/m/Y H:i'),
            'service_order'     => $service->service_order,
        ]);

        $flow = $service->flow()->pluck('status_id')->all();
        $this->flow_status_ids = array_map('intval', $flow);
        $this->flow_order_ids  = array_map('intval', $flow);

        $this->editingLocked = (bool) $service->flow_locked || (bool) optional($service->currentStatus)->is_terminal;
        $this->isViewing = false;
        $this->showForm = true;
    }

    public function openView(int $id): void
    {
        $service = Service::with('flow', 'currentStatus')->findOrFail($id);

        $this->fill([
            'editingId'         => $service->id,
            'client'            => $service->client,
            'cylinder_head'     => $service->cylinder_head,
            'description'       => $service->description,
            'current_status_id' => $service->current_status_id,
            'paid'              => (bool) $service->paid,
            'completed_at'      => optional($service->completed_at)->format('d/m/Y H:i'),
            'service_order'     => $service->service_order,
        ]);

        $flow = $service->flow()->pluck('status_id')->all();
        $this->flow_status_ids = array_map('intval', $flow);
        $this->flow_order_ids  = array_map('intval', $flow);

        $this->editingLocked = true; // não importa; tudo ficará desabilitado
        $this->isViewing     = true;
        $this->showForm      = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->isViewing = false;
    }

    protected function normalizeFlow(): void
    {
        $allowed = Status::where('is_terminal', false)
            ->where('is_selectable', true)
            ->pluck('id')->map(fn($v) => (int)$v)->all();

        $this->flow_status_ids = array_values(array_intersect(
            array_map('intval', $this->flow_status_ids),
            $allowed
        ));

        $currentOrder = array_map('intval', $this->flow_order_ids);
        $this->flow_order_ids = array_values(array_intersect($currentOrder, $this->flow_status_ids));

        foreach ($this->flow_status_ids as $id) {
            if (!in_array($id, $this->flow_order_ids, true)) {
                $this->flow_order_ids[] = $id;
            }
        }
    }

    public function updatedFlowStatusIds(): void
    {
        $this->normalizeFlow();
    }

    public function moveUp(int $statusId): void
    {
        $this->normalizeFlow();

        $statusId = (int) $statusId;
        $idx = array_search($statusId, $this->flow_order_ids, true);

        if ($idx !== false && $idx > 0) {
            [$this->flow_order_ids[$idx - 1], $this->flow_order_ids[$idx]] =
                [$this->flow_order_ids[$idx], $this->flow_order_ids[$idx - 1]];
            $this->flow_order_ids = array_values($this->flow_order_ids);
        }
    }

    public function moveDown(int $statusId): void
    {
        $this->normalizeFlow();

        $statusId = (int) $statusId;
        $idx = array_search($statusId, $this->flow_order_ids, true);

        if ($idx !== false && $idx < count($this->flow_order_ids) - 1) {
            [$this->flow_order_ids[$idx + 1], $this->flow_order_ids[$idx]] =
                [$this->flow_order_ids[$idx], $this->flow_order_ids[$idx + 1]];
            $this->flow_order_ids = array_values($this->flow_order_ids);
        }
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('services.manage'), 403);

        if ($this->isViewing) {
            // apenas ignora / fecha
            $this->showForm = false;
            $this->isViewing = false;
            return;
        }

        $data = $this->validate();

        DB::transaction(function () use ($data) {
            $order = !empty($this->flow_order_ids) ? $this->flow_order_ids : $this->flow_status_ids;

            /** @var \App\Models\Service $service */
            $service = $this->editingId
                ? Service::findOrFail($this->editingId)
                : new Service();

            // se estiver travado (finalizado), só permite pago/descrição
            if ($service->exists && ($service->flow_locked || $service->isTerminal())) {
                $service->update([
                    'paid'        => (bool) $data['paid'],
                    'description' => $data['description'] ?? null,
                ]);

                // NÃO mexe em fluxo/ordem/status/etc.
                return;
            }

            // fluxo normal (não-finalizado)
            $service->fill($data);
            if (!$service->exists) {
                $service->current_status_id = (int) $order[0];
            }
            $service->save();

            // pode editar fluxo/ordem
            $service->flow()->delete();
            foreach (array_values($order) as $i => $statusId) {
                $service->flow()->create([
                    'status_id'  => (int) $statusId,
                    'step_order' => $i + 1,
                ]);
            }

            if ($this->editingId && !in_array($service->current_status_id, $order, true)) {
                $service->updateQuietly([
                    'current_status_id' => (int) $order[0],
                    'completed_at'      => null,
                ]);
            }
        });

        $this->showForm = false;
        $this->dispatch('notify', body: 'Serviço salvo com sucesso.');
    }

    public function confirmDelete(int $id): void
    {
        abort_unless(auth()->user()->can('services.manage'), 403);

        $this->editingId = $id;
        $this->confirmingDelete = true;
    }

    public function delete(): void
    {
        abort_unless(auth()->user()->can('services.manage'), 403);

        Service::whereKey($this->editingId)->delete();
        $this->confirmingDelete = false;
        $this->dispatch('notify', body: 'Serviço excluído.');
    }

    public function startService(int $id): void
    {
        abort_unless(auth()->user()->can('services.start'), 403);
        $service = Service::findOrFail($id);
        if ($service->flow_locked || $service->isTerminal()) {
            $this->dispatch('notify', body: 'Serviço finalizado. Ação não permitida.', type: 'warning');
            return;
        }
        $service->start(auth()->user());
        $this->dispatch('notify', body: 'Serviço iniciado.');
    }

    public function markAsTerminal(int $serviceId, int $statusId): void
    {
        abort_unless(auth()->user()->can('services.change-status'), 403);

        $status = Status::findOrFail($statusId);
        if (!$status->is_terminal) {
            $this->dispatch('notify', body: 'Apenas status terminal permitido aqui.', type: 'warning');
            return;
        }

        $service = Service::with('flow')->findOrFail($serviceId);

        if ($service->flow_locked || $service->isTerminal()) {
            $this->dispatch('notify', body: 'Serviço já finalizado.', type: 'info');
            return;
        }

        $service->finalizeToTerminal(auth()->user(), $status->id);
        $this->dispatch('notify', body: 'Serviço finalizado.');
    }

    public function finishService(int $id): void
    {
        abort_unless(auth()->user()->can('services.finish'), 403);
        $service = Service::findOrFail($id);
        if ($service->flow_locked || $service->isTerminal()) {
            $this->dispatch('notify', body: 'Serviço finalizado. Ação não permitida.', type: 'warning');
            return;
        }
        $service->finish(auth()->user()); // se última, pula para terminal
        $this->dispatch('notify', body: 'Serviço avançado.');
    }

    public function changeStatus(int $id, int $statusId): void
    {
        abort_unless(auth()->user()->can('services.change-status'), 403);

        $service = Service::with('flow')->findOrFail($id);
        $flowIds = $service->flow->pluck('status_id')->all();

        $isAdmin = auth()->user()->hasRole('admin');
        if (!$isAdmin && !in_array($statusId, $flowIds, true)) {
            $this->dispatch('notify', body: 'Este serviço não possui essa etapa na trilha.', type: 'warning');
            return;
        }

        $service->update(['current_status_id' => $statusId]);

        // se foi para o último passo, marca concluído; senão, desmarca
        if (!empty($flowIds) && $statusId === end($flowIds)) {
            $service->updateQuietly(['completed_at' => now()]);
        } else {
            $service->updateQuietly(['completed_at' => null]);
        }

        $this->dispatch('notify', body: 'Status alterado.');
    }


    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->client = '';
        $this->cylinder_head = '';
        $this->description = '';
        $this->paid = false;
        $this->completed_at = null;
        $this->service_order = null;
        $this->current_status_id = Status::query()->value('id');
    }

    public function getStatusesProperty()
    {
        return Status::orderBy('id')->get(['id', 'name', 'slug', 'color', 'is_selectable', 'is_terminal']);
    }

    public function getTerminalStatusesProperty()
    {
        return Status::where('is_terminal', true)->orderBy('id')->get(['id', 'name', 'color']);
    }

    public function openConfirmToggle(int $id): void
    {
        $service = Service::with(['currentStatus', 'logs' => fn($q) => $q->whereNull('finished_at')])
            ->findOrFail($id);

        $hasOpenLog = $service->logs
            ->firstWhere('status_id', $service->current_status_id) !== null;

        $this->toggleId      = $service->id;
        $this->confirmAction = $hasOpenLog ? 'finish' : 'start';

        $acao     = $hasOpenLog ? 'finalizar' : 'iniciar';
        $stName   = optional($service->currentStatus)->name ?? '—';
        $ordem    = $service->service_order ?? $service->id;

        $this->confirmMessage = "Tem certeza que deseja {$acao} o serviço #{$ordem} ({$service->client} – {$service->cylinder_head}) na etapa \"{$stName}\"?";
        $this->confirmingToggle = true;
    }

    public function performToggle(): void
    {
        abort_unless($this->toggleId, 404);

        $service = Service::findOrFail($this->toggleId);

        if ($this->confirmAction === 'finish') {
            abort_unless(auth()->user()->can('services.finish'), 403);
            $service->finish(auth()->user());
            $this->dispatch('notify', body: 'Serviço finalizado e avançado para a próxima etapa.');
        } else {
            abort_unless(auth()->user()->can('services.start'), 403);
            $service->start(auth()->user());
            $this->dispatch('notify', body: 'Serviço iniciado.');
        }

        $this->confirmingToggle = false;
        $this->toggleId = null;
    }


    public function render()
    {
        $query = Service::query()
            ->with(['currentStatus', 'flow', 'logs'])
            ->when($this->search !== '', function ($q) {
                $q->where(function ($q2) {
                    $q2->where('client', 'like', "%{$this->search}%")
                        ->orWhere('cylinder_head', 'like', "%{$this->search}%")
                        ->orWhere('service_order', $this->search);
                });
            })
            ->when($this->statusFilter, fn($q) => $q->where('current_status_id', $this->statusFilter))
            ->when($this->paidFilter !== null && $this->paidFilter !== '', fn($q) => $q->where('paid', (int)$this->paidFilter))
            ->latest('id');

        return view('livewire.services.service-crud', [
            'services' => $query->paginate(10),
        ]);
    }
}
