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
    public bool $hasProgress = false;
    public bool $isRunningAtOpen = false;
    public bool $isTerminalAtOpen = false;
    public bool $isFlowLockedAtOpen = false;
    public array $locked_prefix_ids = [];
    public bool $confirmingManualFinalize = false;
    public ?int $manualFinalizeServiceId = null;
    public ?int $manualFinalizeStatusId  = null;
    public string $manualFinalizeMessage = '';

    public function rules(): array
    {
        return [
            'client'            => ['required', 'string', 'max:255'],
            'cylinder_head'     => ['required', 'string', 'max:255'],
            'description'       => ['nullable', 'string'],

            // ⛔ current_status_id só pode ser status NÃO terminal e selecionável
            'current_status_id' => [
                'required',
                'integer',
                Rule::exists('statuses', 'id')->where(
                    fn($q) => $q->where('is_terminal', false)
                        ->where('is_selectable', true)
                ),
            ],

            // ✅ fluxo: só status NÃO terminais e selecionáveis
            'flow_status_ids'   => ['required', 'array', 'min:1'],
            'flow_status_ids.*' => [
                'integer',
                Rule::exists('statuses', 'id')->where(
                    fn($q) => $q->where('is_terminal', false)
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
        $firstAllowed = Status::where('is_terminal', false)
            ->where('is_selectable', true)
            ->orderBy('id')
            ->value('id');

        $this->current_status_id = (int) $firstAllowed;
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

        $first = Status::where('is_terminal', false)
            ->where('is_selectable', true)
            ->orderBy('id')
            ->limit(1)
            ->pluck('id')
            ->all();

        $this->flow_status_ids = array_map('intval', $first);
        $this->flow_order_ids  = array_map('intval', $first);

        $this->hasProgress = false;
        $this->isRunningAtOpen = false;
        $this->isTerminalAtOpen = false;
        $this->isFlowLockedAtOpen = false;
        $this->locked_prefix_ids = [];
        $this->paid = false;
        $this->editingLocked = false;
        $this->isViewing = false;
        $this->showForm = true;
    }

    public function openEdit(int $id): void
    {
        abort_unless(auth()->user()->can('services.manage'), 403);

        // 🔁 limpa erros antigos do Livewire/validator
        $this->resetValidation();
        $this->resetErrorBag();

        $service = Service::with('flow', 'currentStatus', 'logs')->findOrFail($id);

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

        $flow = $service->flow()->orderBy('step_order')->pluck('status_id')->values()->all();
        $this->flow_status_ids = array_map('intval', $flow);
        $this->flow_order_ids  = array_map('intval', $flow);

        // flags para o banner e regras
        $this->isTerminalAtOpen   = (bool) optional($service->currentStatus)->is_terminal;
        $this->isFlowLockedAtOpen = (bool) $service->flow_locked;
        $this->isRunningAtOpen    = $service->hasOpenLogForCurrent();
        $this->hasProgress        = $service->hasAnyProgress();

        // 🔒 o formulário TODO só fica travado se finalizado/flow_locked/rodando
        $this->editingLocked = $this->isTerminalAtOpen || $this->isFlowLockedAtOpen || $this->isRunningAtOpen;

        // prefixo imutável (se já houve progresso)
        $this->locked_prefix_ids = [];
        if ($this->hasProgress) {
            $idx = array_search((int)$service->current_status_id, $flow, true);
            if ($idx !== false) {
                $this->locked_prefix_ids = array_map('intval', array_slice($flow, 0, $idx + 1));
            }
        }

        $this->isViewing = false;
        $this->showForm  = true;
    }

    public function openView(int $id): void
    {
        abort_unless(auth()->user()->can('services.view'), 403);
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
        $this->hasProgress = false;
        $this->showForm      = true;
    }

    public function openConfirmManualFinalize(int $serviceId, int $statusId): void
    {
        abort_unless(auth()->user()->can('services.change-status'), 403);

        $service = Service::with(['currentStatus'])->findOrFail($serviceId);
        $status  = Status::findOrFail($statusId);

        if (!$status->is_terminal) {
            $this->dispatch('notify', body: 'Apenas status terminal permitido aqui.', type: 'warning');
            return;
        }

        // Mensagem amigável
        $ordem  = $service->service_order ?? $service->id;
        $stName = $status->name;

        $this->manualFinalizeServiceId = $service->id;
        $this->manualFinalizeStatusId  = $status->id;
        $this->manualFinalizeMessage   = "Tem certeza que deseja finalizar o serviço #{$ordem} para o status \"{$stName}\"?";
        $this->confirmingManualFinalize = true;
    }

    public function performManualFinalize(): void
    {
        abort_unless(auth()->user()->can('services.change-status'), 403);
        abort_unless($this->manualFinalizeServiceId && $this->manualFinalizeStatusId, 404);

        $service = Service::with('flow')->findOrFail($this->manualFinalizeServiceId);
        $status  = Status::findOrFail($this->manualFinalizeStatusId);

        if (!$status->is_terminal) {
            $this->dispatch('notify', body: 'Apenas status terminal permitido aqui.', type: 'warning');
            return;
        }

        if ($service->flow_locked || $service->isTerminal()) {
            $this->dispatch('notify', body: 'Serviço já finalizado.', type: 'info');
        } else {
            $service->finalizeToTerminal(auth()->user(), $status->id);
            $this->dispatch('notify', body: 'Serviço finalizado.');
        }

        // limpar estado do modal
        $this->confirmingManualFinalize = false;
        $this->manualFinalizeServiceId  = null;
        $this->manualFinalizeStatusId   = null;
        $this->manualFinalizeMessage    = '';
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->isViewing = false;
    }

    protected function normalizeFlow(): void
    {
        // mantém apenas status permitidos
        $allowed = Status::where('is_terminal', false)
            ->where('is_selectable', true)
            ->pluck('id')->map(fn($v) => (int)$v)->all();

        $this->flow_status_ids = array_values(array_intersect(
            array_map('intval', $this->flow_status_ids),
            $allowed
        ));

        // ordem atual “sanitizada”
        $currentOrder = array_map('intval', $this->flow_order_ids);
        $this->flow_order_ids = array_values(array_intersect($currentOrder, $this->flow_status_ids));

        // inclui faltantes ao final
        foreach ($this->flow_status_ids as $id) {
            if (!in_array($id, $this->flow_order_ids, true)) {
                $this->flow_order_ids[] = $id;
            }
        }

        // ⚙️ Reforço: se já houve progresso, prefixo é imutável
        if ($this->editingId && $this->hasProgress && !empty($this->locked_prefix_ids)) {
            // 1) garante que o prefixo SEMPRE esteja selecionado
            $this->flow_status_ids = array_values(array_unique(array_merge(
                $this->locked_prefix_ids,
                $this->flow_status_ids
            )));

            // 2) garante prefixo no início e na mesma ordem no flow_order_ids
            $ordered = array_values(array_intersect($this->flow_order_ids, $this->flow_status_ids));

            // remove prefixo do meio e re-prepende
            $orderedWithoutPrefix = array_values(array_diff($ordered, $this->locked_prefix_ids));
            $this->flow_order_ids = array_values(array_merge($this->locked_prefix_ids, $orderedWithoutPrefix));
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

        if ($this->hasProgress && in_array($statusId, $this->locked_prefix_ids, true)) {
            return; // 🔒 não move prefixo
        }

        $idx = array_search($statusId, $this->flow_order_ids, true);
        if ($idx !== false && $idx > 0) {
            // impede que item ultrapasse o fim do prefixo
            $minIdx = $this->hasProgress ? count($this->locked_prefix_ids) : 0;
            if ($this->hasProgress && $idx - 1 < $minIdx) return;

            [$this->flow_order_ids[$idx - 1], $this->flow_order_ids[$idx]] =
                [$this->flow_order_ids[$idx], $this->flow_order_ids[$idx - 1]];
            $this->flow_order_ids = array_values($this->flow_order_ids);
        }
    }

    public function moveDown(int $statusId): void
    {
        $this->normalizeFlow();
        $statusId = (int) $statusId;

        if ($this->hasProgress && in_array($statusId, $this->locked_prefix_ids, true)) {
            return; // 🔒 não move prefixo
        }

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
            $this->showForm = false;
            $this->isViewing = false;
            return;
        }

        $data = $this->validate();

        DB::transaction(function () use ($data) {
            // --- (A) Normalização/segurança do $order ---
            $order = !empty($this->flow_order_ids) ? $this->flow_order_ids : $this->flow_status_ids;

            // força inteiros e únicos, preservando a primeira ocorrência
            $order = array_values(array_unique(array_map('intval', $order)));

            // mantém só status permitidos (não-terminais e selecionáveis)
            $allowed = Status::query()
                ->where('is_terminal', false)
                ->where('is_selectable', true)
                ->pluck('id')->map(fn($v) => (int)$v)->all();

            $order = array_values(array_intersect($order, $allowed));

            if (empty($order)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'flow_status_ids' => 'Selecione ao menos uma etapa válida para o fluxo.',
                ]);
            }

            /** @var \App\Models\Service $service */
            $service = $this->editingId
                ? Service::with('flow', 'logs', 'currentStatus')->findOrFail($this->editingId)
                : new Service();

            // --- (B) Bloqueios duros já existentes ---
            if ($service->exists) {
                if ($service->flow_locked || $service->isTerminal()) {
                    $service->update([
                        'paid'        => (bool) $data['paid'],
                        'description' => $data['description'] ?? null,
                    ]);
                    return;
                }

                if ($service->hasOpenLogForCurrent()) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'flow_status_ids' => 'Serviço em execução: encerre a etapa atual para alterar o fluxo.',
                    ]);
                }
            }

            // --- (C) Regras quando já houve progresso ---
            if ($service->exists && $service->hasAnyProgress()) {
                // 1) A etapa atual DEVE existir no novo fluxo
                if (!in_array((int)$service->current_status_id, $order, true)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'flow_status_ids' => 'O fluxo precisa conter a etapa atual do serviço.',
                    ]);
                }

                // 2) Prefixo até a etapa atual é imutável
                $currentFlow  = $service->flow()->orderBy('step_order')->pluck('status_id')->values()->all();
                $currentIndex = $service->currentIndexInFlow(); // 0-based ou -1

                if ($currentIndex < 0) {
                    // current não está no fluxo salvo -> trate como erro claro
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'flow_status_ids' => 'Fluxo inconsistente: a etapa atual não consta no fluxo salvo. Reinclua a etapa atual antes de salvar.',
                    ]);
                }

                $oldPrefix = array_map('intval', array_slice($currentFlow, 0, $currentIndex + 1));
                $newPrefix = array_map('intval', array_slice($order,      0, $currentIndex + 1));

                if ($oldPrefix !== $newPrefix) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'flow_status_ids' => 'Não é permitido alterar etapas já percorridas (antes ou incluindo a etapa atual).',
                    ]);
                }
            }

            // --- (D) Persistência normal (como você já fazia) ---
            $service->fill($data);

            if (!$service->exists) {
                $service->current_status_id = (int) $order[0];
                $service->completed_at = null;
                $service->save();
            } else {
                if (!$service->hasAnyProgress()) {
                    $service->update([
                        'current_status_id' => (int) $order[0],
                        'completed_at'      => null,
                    ]);
                }
            }

            $service->flow()->delete();
            foreach (array_values($order) as $i => $statusId) {
                $service->flow()->create([
                    'status_id'  => (int) $statusId,
                    'step_order' => $i + 1,
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
        $target  = Status::findOrFail($statusId);

        // ⛔ se estiver em execução, não troca status "no clique"
        if ($service->hasOpenLogForCurrent()) {
            $this->dispatch('notify', body: 'Serviço em execução: encerre a etapa atual para avançar.', type: 'warning');
            return;
        }

        if ($target->is_terminal || !$target->is_selectable) {
            $this->dispatch('notify', body: 'Status inválido para seleção direta.', type: 'warning');
            return;
        }

        $flowIds = $service->flow->pluck('status_id')->all();
        if (!in_array($statusId, $flowIds, true)) {
            $this->dispatch('notify', body: 'Este serviço não possui essa etapa na trilha.', type: 'warning');
            return;
        }

        $service->update(['current_status_id' => $statusId]);

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
        $this->current_status_id = (int) Status::where('is_terminal', false)
            ->where('is_selectable', true)
            ->orderBy('id')
            ->value('id');
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
