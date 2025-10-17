<?php

namespace App\Livewire\Services;

use App\Models\Service;
use App\Models\Status;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

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
        $this->showForm = true;
    }

    public function openEdit(int $id): void
    {
        abort_unless(auth()->user()->can('services.manage'), 403);

        $service = Service::findOrFail($id);
        $this->fill([
            'editingId'        => $service->id,
            'client'           => $service->client,
            'cylinder_head'    => $service->cylinder_head,
            'description'      => $service->description,
            'current_status_id' => $service->current_status_id,
            'paid'             => (bool) $service->paid,
            'completed_at'     => optional($service->completed_at)->format('Y-m-d'),
            'service_order'    => $service->service_order,
        ]);
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('services.manage'), 403);

        $data = $this->validate();

        if ($this->editingId) {
            Service::whereKey($this->editingId)->update($data);
        } else {
            Service::create($data);
        }

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

        // regra simples: marcar como não concluído
        $service->update([
            'paid' => $service->paid, // sem mudança
        ]);

        $this->dispatch('notify', body: 'Serviço iniciado.');
    }

    public function finishService(int $id): void
    {
        abort_unless(auth()->user()->can('services.finish'), 403);

        $service = Service::findOrFail($id);

        // avançar para próximo status + setar completed_at se FINALIZADO
        $nextStatusId = $this->nextStatusId($service->current_status_id);
        $payload = ['current_status_id' => $nextStatusId];

        $finalSlug = 'finalizado';
        $isFinal = Status::whereKey($nextStatusId)->where('slug', $finalSlug)->exists();
        if ($isFinal && !$service->completed_at) {
            $payload['completed_at'] = now()->toDateString();
        }

        $service->update($payload);

        $this->dispatch('notify', body: 'Serviço finalizado/avançado para a próxima etapa.');
    }

    public function changeStatus(int $id, int $statusId): void
    {
        abort_unless(auth()->user()->can('services.change-status'), 403);

        Service::whereKey($id)->update(['current_status_id' => $statusId]);
        $this->dispatch('notify', body: 'Status alterado.');
    }

    protected function nextStatusId(int $currentId): int
    {
        // Estratégia simples: pela ordem do ID (funciona se seed manteve ordem)
        $ids = Status::orderBy('id')->pluck('id')->values();
        $pos = $ids->search($currentId);
        if ($pos === false || $pos === $ids->count() - 1) {
            // se já é o último, mantém (ou poderia voltar ao primeiro)
            return $currentId;
        }
        return (int) $ids[$pos + 1];
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
