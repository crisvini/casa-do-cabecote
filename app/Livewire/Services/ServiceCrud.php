<?php

namespace App\Livewire\Services;

use App\Models\Service;
use App\Models\Status;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
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
    public ?string $paidFilter = null; // '1','0',null

    // form (create/edit)
    public ?int $editingId = null;
    public ?int $current_status_id = null;
    public array $flow_status_ids = [];
    public array $flow_order_ids = [];
    public ?string $client = '';
    public ?string $cylinder_head = '';
    public ?string $description = '';
    public ?string $completed_at = null; // 'YYYY-MM-DD'
    public bool $paid = false;
    public ?int $service_order = null;

    public bool $showForm = false;
    public bool $confirmingDelete = false;

    public function rules(): array
    {
        return [
            'client'            => ['required', 'string', 'max:255'],
            'cylinder_head'     => ['required', 'string', 'max:255'],
            'description'       => ['nullable', 'string'],
            'current_status_id' => ['required', Rule::exists('statuses', 'id')],
            'flow_status_ids'   => ['required', 'array', 'min:1'],
            'flow_status_ids.*' => ['integer', Rule::exists('statuses', 'id')],
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

        // sugere pelo menos 1 status
        $first = Status::query()->orderBy('id')->limit(1)->pluck('id')->all();
        $this->flow_status_ids = $first;
        $this->flow_order_ids  = $first;

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
            'completed_at'      => optional($service->completed_at)->format('Y-m-d'),
            'service_order'     => $service->service_order,
        ]);

        // selecionados = flow; ordem = flow
        $flow = $service->flow()->pluck('status_id')->all();
        $this->flow_status_ids = $flow;
        $this->flow_order_ids  = $flow;

        $this->showForm = true;
    }

    public function updatedFlowStatusIds(): void
    {
        // mantém apenas os que ainda estão selecionados, na ordem atual
        $this->flow_order_ids = array_values(array_intersect($this->flow_order_ids, $this->flow_status_ids));
        // adiciona novos ao final
        foreach ($this->flow_status_ids as $id) {
            if (!in_array($id, $this->flow_order_ids, true)) {
                $this->flow_order_ids[] = $id;
            }
        }
    }

    public function reorderFlow(array $orderedIds): void
    {
        // recebe ['3','7','9', ...] e guarda como int
        $this->flow_order_ids = array_map('intval', $orderedIds);
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('services.manage'), 403);
        $data = $this->validate();

        DB::transaction(function () use ($data) {
            // se não arrastou nada, usa a seleção como fallback
            $order = !empty($this->flow_order_ids) ? $this->flow_order_ids : $this->flow_status_ids;

            if (!$this->editingId) {
                $data['current_status_id'] = (int) $order[0];
            }

            /** @var \App\Models\Service $service */
            $service = $this->editingId
                ? tap(Service::findOrFail($this->editingId))->update($data)
                : Service::create($data);

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
                    'completed_at'      => null
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
        $service->start(auth()->user()); // cria/garante o log de início do status atual

        $this->dispatch('notify', body: 'Serviço iniciado.');
    }

    public function finishService(int $id): void
    {
        abort_unless(auth()->user()->can('services.finish'), 403);

        $service = Service::findOrFail($id);
        $service->finish(auth()->user()); // finaliza o passo atual e AVANÇA pro PRÓXIMO da trilha

        $this->dispatch('notify', body: 'Serviço avançado para a próxima etapa.');
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

    #[On('service-updated')] // gancho se quiser emitir de outros lugares
    public function refreshList(): void {}

    public function getStatusesProperty()
    {
        return Status::orderBy('id')->get(['id', 'name', 'slug', 'color']);
    }

    public function render()
    {
        $query = Service::query()
            ->with('currentStatus')
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
