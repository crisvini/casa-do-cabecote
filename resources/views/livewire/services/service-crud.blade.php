<section class="w-full">
    @include('partials.crud-heading', [
        'title' => 'Serviços',
        'subtitle' => 'Gerencie seus serviços',
    ])

    <div class="space-y-4" wire:poll.15s> {{-- atualização simples a cada 15s --}}
        <div class="flex flex-wrap items-center gap-3">
            <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass"
                placeholder="Buscar por cliente, cabeçote ou ordem..." class="w-full md:w-1/3" />

            <flux:select wire:model.live="statusFilter" placeholder="Filtrar status" class="w-full md:w-56">
                <flux:select.option :value="null" label="Todos" />
                @foreach ($this->statuses as $st)
                    <flux:select.option :value="$st->id" :label="$st->name" />
                @endforeach
            </flux:select>

            <flux:select wire:model.live="paidFilter" placeholder="Pago?" class="w-full md:w-40">
                <flux:select.option :value="null" label="Todos" />
                <flux:select.option value="1" label="Pago" />
                <flux:select.option value="0" label="Em aberto" />
            </flux:select>

            @can('services.manage')
                <flux:button icon="plus" variant="primary" color="green" class="btn-primary ml-auto w-full md:w-auto"
                    wire:click="openCreate">
                    Novo serviço
                </flux:button>
            @endcan
        </div>

        <flux:table :paginate="$services" class="w-full !table-auto"
            container:class="overflow-auto max-h-screen rounded-lg" locale="pt-BR">
            <flux:table.columns sticky>
                <flux:table.column class="text-center">#</flux:table.column>
                <flux:table.column class="text-center">O.S.</flux:table.column>
                <flux:table.column class="truncate">Cliente</flux:table.column>
                <flux:table.column class="truncate">Cabeçote</flux:table.column>
                <flux:table.column class="text-center">Status Atual</flux:table.column>
                <flux:table.column class="text-center">Encerrar/Parar</flux:table.column>
                <flux:table.column class="text-center">Pago</flux:table.column>
                <flux:table.column class="text-center">Concluído em</flux:table.column>
                <flux:table.column class="text-center">Em execução?</flux:table.column>
                <flux:table.column align="end" class="text-end">Ações</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($services as $row)
                    <flux:table.row :key="$row->id" class="border-b-1 border-zinc-800/5 dark:border-white/10">
                        <flux:table.cell>{{ $row->id }}</flux:table.cell>
                        <flux:table.cell>{{ $row->service_order ?? '—' }}</flux:table.cell>
                        <flux:table.cell class="truncate" title="{{ $row->client }}">{{ $row->client }}
                        </flux:table.cell>
                        <flux:table.cell class="truncate" title="{{ $row->cylinder_head }}">{{ $row->cylinder_head }}
                        </flux:table.cell>

                        <flux:table.cell>
                            @php
                                // ids do flow na ordem (service_status_flows.step_order)
                                $flowIds = $row->flow->pluck('status_id')->all();

                                // mapa id => Status para pegar name/color sem perder a ordem do flow
                                $statusMap = $this->statuses->whereIn('id', $flowIds)->keyBy('id');

                                // status atual (para o botão)
                                $st = $row->currentStatus;
                            @endphp

                            @can('services.change-status')
                                <flux:dropdown>
                                    <flux:button size="sm" :disabled="$row->completed_at ? true : false"
                                        class="!px-2 w-full !py-1 rounded-md shadow-sm border border-black/10 dark:border-white/10"
                                        style="background-color: {{ $st?->color ?? '#E5E7EB' }}; color: {{ contrast_color($st?->color ?? '#E5E7EB') }};"
                                        icon:trailing="chevron-down">
                                        {{ $st?->name ?? '—' }}
                                    </flux:button>

                                    <flux:menu class="w-auto">
                                        @forelse ($flowIds as $sid)
                                            @php $opt = $statusMap->get((int)$sid); @endphp
                                            @if ($opt)
                                                <flux:menu.item
                                                    wire:click="changeStatus({{ $row->id }}, {{ $opt->id }})"
                                                    class="flex items-center gap-2">
                                                    <span class="inline-flex w-3.5 h-3.5 rounded-full ring-1 ring-black/10"
                                                        style="background-color: {{ $opt->color }};"></span>
                                                    <span class="flex-1">{{ $opt->name }}</span>
                                                </flux:menu.item>
                                            @endif
                                        @empty
                                            <div class="px-3 py-2 text-xs opacity-70">Sem fluxo definido</div>
                                        @endforelse
                                    </flux:menu>
                                </flux:dropdown>
                            @else
                                <span
                                    class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium ring-1 ring-black/10"
                                    style="background-color: {{ $st?->color ?? '#E5E7EB' }}; color: {{ contrast_color($st?->color ?? '#E5E7EB') }};">
                                    {{ $st?->name ?? '—' }}
                                </span>
                            @endcan
                        </flux:table.cell>

                        <flux:table.cell>
                            @php
                                $isLocked = (bool) $row->flow_locked;
                                $isTerminal = (bool) optional($row->currentStatus)->is_terminal;
                            @endphp

                            @if ($isTerminal || $isLocked)
                                <flux:badge size="sm" variant="solid" color="green">Finalizado</flux:badge>
                            @else
                                @can('services.change-status')
                                    <flux:dropdown>
                                        <flux:button size="sm" class="!px-2 !py-1" icon="check-badge"
                                            icon:trailing="chevron-down" :disabled="$row->in_progress">
                                            Finalizar
                                        </flux:button>

                                        <flux:menu class="w-auto">
                                            @forelse ($this->terminalStatuses as $term)
                                                <flux:menu.item
                                                    wire:click="markAsTerminal({{ $row->id }}, {{ $term->id }})"
                                                    class="flex items-center gap-2">
                                                    <span class="inline-flex w-3.5 h-3.5 rounded-full ring-1 ring-black/10"
                                                        style="background-color: {{ $term->color }};"></span>
                                                    <span class="flex-1">{{ $term->name }}</span>
                                                </flux:menu.item>
                                            @empty
                                                <div class="px-3 py-2 text-xs opacity-70">Sem status terminal</div>
                                            @endforelse
                                        </flux:menu>
                                    </flux:dropdown>
                                @endcan
                            @endif
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge size="sm" variant="solid" :color="$row->paid ? 'green' : 'amber'">
                                {{ $row->paid ? 'Sim' : 'Não' }}
                            </flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>{{ optional($row->completed_at)->format('d/m/Y H:i') ?? '—' }}
                        </flux:table.cell>

                        <flux:table.cell>
                            @if ($row->in_progress)
                                <flux:badge size="sm" variant="solid" color="blue"
                                    class="flex items-center gap-1">
                                    <span>Sim</span>
                                    <svg class="h-3.5 w-3.5 text-white/80 animate-spin"
                                        xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10"
                                            stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor"
                                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                        </path>
                                    </svg>
                                </flux:badge>
                            @else
                                <flux:badge size="sm" variant="solid" color="zinc">
                                    Não
                                </flux:badge>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell align="end">
                            @php
                                $isRunning = $row->logs
                                    ->where('status_id', $row->current_status_id)
                                    ->whereNull('finished_at')
                                    ->isNotEmpty();

                                $canStart = auth()->user()->can('services.start');
                                $canFinish = auth()->user()->can('services.finish');
                                $canToggle = ($isRunning && $canFinish) || (!$isRunning && $canStart);
                                $isLocked = (bool) $row->flow_locked;
                                $isTerminal = (bool) optional($row->currentStatus)->is_terminal;
                            @endphp

                            <div class="flex flex-row gap-2 justify-end">
                                @if ($canToggle)
                                    <flux:tooltip
                                        :content="$row->completed_at ? 'Serviço já finalizado' : ($isRunning ? 'Finalizar' : 'Iniciar')">
                                        <flux:button size="sm" :icon="$isRunning ? 'check' : 'play'"
                                            :color="$isRunning ? 'green' : null" variant="primary"
                                            class="cursor-pointer" wire:click="openConfirmToggle({{ $row->id }})"
                                            :disabled="($row->completed_at || ($isLocked && $isTerminal)) ? true : false" />
                                    </flux:tooltip>
                                @endif

                                <flux:tooltip content="Ver">
                                    <flux:button size="sm" icon="eye" variant="primary" class="cursor-pointer"
                                        wire:click="openView({{ $row->id }})" />
                                </flux:tooltip>

                                @can('services.manage')
                                    <flux:tooltip content="Editar">
                                        <flux:button size="sm" icon="pencil-square" variant="primary"
                                            class="cursor-pointer" wire:click="openEdit({{ $row->id }})" />
                                    </flux:tooltip>
                                    <flux:tooltip content="Excluir">
                                        <flux:button size="sm" icon="trash" color="red" variant="primary"
                                            class="cursor-pointer" wire:click="confirmDelete({{ $row->id }})" />
                                    </flux:tooltip>
                                @endcan
                            </div>
                        </flux:table.cell>

                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        {{-- Modal Form --}}
        <x-modal wire:model="showForm" separator>
            @php $statusesById = $this->statuses->keyBy('id'); @endphp

            <div class="mb-3">
                <flux:heading size="lg">
                    {{ $isViewing ? 'Visualizar serviço' : ($editingId ? 'Editar serviço' : 'Novo serviço') }}
                </flux:heading>
            </div>

            {{-- aviso quando travado --}}
            @if ($editingLocked && !$isViewing)
                <div
                    class="mt-5 mb-3 text-xs p-2 rounded bg-amber-100 text-amber-900 dark:bg-amber-900/30 dark:text-amber-200">
                    Serviço finalizado: somente <strong>Pago</strong> e <strong>Descrição</strong> podem ser
                    editados.
                </div>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <flux:input label="Cliente" wire:model="client" :disabled="$isViewing || $editingLocked" required />
                <flux:input label="Cabeçote" wire:model="cylinder_head" :disabled="$isViewing || $editingLocked"
                    required />
                <flux:input label="O.S. (opcional)" wire:model="service_order" type="number" min="1"
                    :disabled="$isViewing || $editingLocked" />

                <div class="md:col-span-2">
                    <flux:select variant="listbox" multiple searchable label="Fluxo (status incluídos)"
                        wire:model.live="flow_status_ids" placeholder="Selecione os status"
                        :disabled="$isViewing || $editingLocked">
                        @foreach ($this->statuses->where('is_terminal', false)->where('is_selectable', true) as $st)
                            <flux:select.option :value="$st->id">{{ $st->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <p class="text-xs text-zinc-500 mt-1">
                        Selecione os status. A <strong>ordem</strong> é definida abaixo com os botões.
                    </p>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-sm font-medium mb-1">Ordem de execução</label>

                    @php
                        $statusesById = $this->statuses->keyBy('id');
                        $orderedVisible = array_values(
                            array_filter($flow_order_ids, fn($id) => in_array((int) $id, $flow_status_ids, true)),
                        );
                        $lastIndex = count($orderedVisible) - 1;
                    @endphp

                    <ol class="space-y-2">
                        @foreach ($orderedVisible as $i => $sid)
                            @php $s = $statusesById->get((int) $sid); @endphp
                            <li
                                class="flex items-center justify-between gap-2 p-2 rounded ring-1 ring-black/10 dark:ring-white/10">
                                <div class="flex items-center gap-2">
                                    <span class="text-xs opacity-70 w-6">#{{ $i + 1 }}</span>
                                    <span class="px-2 py-0.5 text-xs rounded ring-1 ring-black/10"
                                        style="background-color: {{ $s?->color ?? '#E5E7EB' }}; color: {{ contrast_color($s?->color ?? '#E5E7EB') }};">
                                        {{ $s?->name ?? '—' }}
                                    </span>
                                </div>

                                <div class="flex items-center gap-1">
                                    <flux:button size="xs" variant="ghost" icon="chevron-up"
                                        wire:click="moveUp({{ (int) $sid }})"
                                        :disabled="$isViewing || $editingLocked || $i === 0" />
                                    <flux:button size="xs" variant="ghost" icon="chevron-down"
                                        wire:click="moveDown({{ (int) $sid }})"
                                        :disabled="$isViewing || $editingLocked || $i === $lastIndex" />
                                </div>
                            </li>
                        @endforeach
                    </ol>

                    @if ($lastIndex < 0)
                        <p class="text-xs text-zinc-500 mt-2">Selecione ao menos um status acima.</p>
                    @endif
                </div>

                {{-- SEMPRE editáveis --}}
                <flux:select wire:model="paid" label="Pago?" :disabled="$isViewing">
                    <flux:select.option value="0" label="Não" />
                    <flux:select.option value="1" label="Sim" />
                </flux:select>

                <flux:input label="Concluído em" wire:model="completed_at" disabled />

                <div class="md:col-span-2">
                    <x-textarea label="Descrição" wire:model="description" rows="3" :disabled="$isViewing" />
                </div>
            </div>

            <div class="flex justify-end items-center mt-2 gap-2">
                <flux:button variant="ghost"
                    wire:click="{{ $isViewing ? 'closeForm' : '$set(\'showForm\', false)' }}">Fechar</flux:button>
                @if (!$isViewing)
                    <flux:button variant="primary" color="green" wire:click="save" icon="check">Salvar
                    </flux:button>
                @endif
            </div>
        </x-modal>

        {{-- Modal Delete --}}
        <x-modal wire:model="confirmingDelete" title="Confirmar exclusão" icon="o-exclamation-triangle" separator>
            <p>Tem certeza que deseja excluir este serviço? Esta ação não pode ser desfeita.</p>
            <div class="flex justify-end items-center mt-2 gap-2">
                <flux:button variant="ghost" class="btn-ghost" wire:click="$set('confirmingDelete', false)">
                    Cancelar
                </flux:button>
                <flux:button variant="primary" color="red" wire:click="delete" icon="trash">Excluir
                </flux:button>
            </div>
        </x-modal>

        <x-modal wire:model="confirmingToggle" title="Confirmar ação" separator>
            <p>{{ $confirmMessage }}</p>
            <div class="flex justify-end items-center mt-2 gap-2">
                <flux:button class="btn-ghost" wire:click="$set('confirmingToggle', false)">Cancelar</flux:button>
                <flux:button :color="$confirmAction === 'finish' ? 'green' : null"
                    :icon="$confirmAction === 'finish' ? 'check' : 'play'" wire:click="performToggle">
                    Confirmar
                </flux:button>
            </div>
        </x-modal>

    </div>
</section>
