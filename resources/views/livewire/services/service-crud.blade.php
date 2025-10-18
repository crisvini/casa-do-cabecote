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
                <flux:button icon="plus" class="btn-primary ml-auto w-full md:w-auto" wire:click="openCreate">
                    Novo serviço
                </flux:button>
            @endcan
        </div>

        <flux:table :paginate="$services" class="w-full !table-auto"
            container:class="overflow-auto max-h-screen rounded-lg" locale="pt-BR">
            <flux:table.columns sticky>
                <flux:table.column class="text-center">#</flux:table.column>
                <flux:table.column class="text-center">Ordem</flux:table.column>
                <flux:table.column class="truncate">Cliente</flux:table.column>
                <flux:table.column class="truncate">Cabeçote</flux:table.column>
                <flux:table.column class="text-center">Status</flux:table.column>
                <flux:table.column class="text-center">Pago</flux:table.column>
                <flux:table.column class="text-center">Concluído em</flux:table.column>
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
                                $st = $row->currentStatus;
                                $isAdmin = auth()->user()->hasRole('admin');
                                // status permitidos para troca: admin vê todos; demais veem só a trilha do serviço
                                $allowed = $isAdmin
                                    ? $this->statuses
                                    : $this->statuses->whereIn('id', $row->flow->pluck('status_id'));
                            @endphp

                            @can('services.change-status')
                                <flux:dropdown>
                                    <flux:button size="sm"
                                        class="!px-2 w-full !py-1 rounded-md shadow-sm border border-black/10 dark:border-white/10"
                                        style="background-color: {{ $st?->color ?? '#E5E7EB' }}; color: {{ contrast_color($st?->color ?? '#E5E7EB') }};"
                                        icon:trailing="chevron-down">
                                        {{ $st?->name ?? '—' }}
                                    </flux:button>

                                    <flux:menu class="w-auto">
                                        @foreach ($allowed as $opt)
                                            <flux:menu.item
                                                wire:click="changeStatus({{ $row->id }}, {{ $opt->id }})"
                                                class="flex items-center gap-2">
                                                <span class="inline-flex w-3.5 h-3.5 rounded-full ring-1 ring-black/10"
                                                    style="background-color: {{ $opt->color }};"></span>
                                                <span class="flex-1">{{ $opt->name }}</span>
                                            </flux:menu.item>
                                        @endforeach
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
                            <flux:badge size="sm" variant="solid" :color="$row->paid ? 'green' : 'amber'">
                                {{ $row->paid ? 'Sim' : 'Não' }}
                            </flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>{{ optional($row->completed_at)->format('d/m/Y') ?? '—' }}</flux:table.cell>

                        <flux:table.cell align="end">
                            <div class="flex flex-row gap-2 justify-end">
                                @can('services.start')
                                    <flux:tooltip content="Iniciar">
                                        <flux:button size="sm" icon="play" variant="ghost" class="cursor-pointer"
                                            wire:click="startService({{ $row->id }})" />
                                    </flux:tooltip>
                                @endcan
                                @can('services.finish')
                                    <flux:tooltip content="Finalizar">
                                        <flux:button size="sm" icon="check" color="green" class="cursor-pointer"
                                            wire:click="finishService({{ $row->id }})" />
                                    </flux:tooltip>
                                @endcan
                                @can('services.manage')
                                    <flux:tooltip content="Editar">
                                        <flux:button size="sm" icon="pencil-square" variant="ghost"
                                            class="cursor-pointer" wire:click="openEdit({{ $row->id }})" />
                                    </flux:tooltip>
                                    <flux:tooltip content="Excluir">
                                        <flux:button size="sm" icon="trash" color="red" class="cursor-pointer"
                                            wire:click="confirmDelete({{ $row->id }})" />
                                    </flux:tooltip>
                                @endcan
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>

        {{-- Modal Form --}}
        <x-modal wire:model="showForm" title="{{ $editingId ? 'Editar serviço' : 'Novo serviço' }}" separator>
            @php $statusesById = $this->statuses->keyBy('id'); @endphp

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <flux:input label="Cliente" wire:model="client" required />
                <flux:input label="Cabeçote" wire:model="cylinder_head" required />
                <flux:input label="Ordem (opcional)" wire:model="service_order" type="number" min="1" />

                {{-- NOVO: multiselect da trilha (ordem das etapas) --}}
                <div class="md:col-span-2">
                    <flux:select variant="listbox" multiple searchable label="Fluxo (ordem das etapas)"
                        wire:model="flow_status_ids" placeholder="Selecione as etapas na ordem desejada">
                        @foreach ($this->statuses as $st)
                            <flux:select.option :value="$st->id">{{ $st->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <p class="text-xs text-zinc-500 mt-1">
                        Selecione os status na ordem em que o serviço deve avançar. O primeiro será o status inicial.
                    </p>

                    {{-- Prévia da ordem escolhida (opcional, ajuda visual) --}}
                    @if (!empty($flow_status_ids))
                        <div class="flex flex-wrap gap-2 mt-2">
                            @foreach ($flow_status_ids as $i => $sid)
                                @php $s = $statusesById->get((int)$sid); @endphp
                                <span
                                    class="inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded ring-1 ring-black/10"
                                    style="background-color: {{ $s?->color ?? '#E5E7EB' }}; color: {{ contrast_color($s?->color ?? '#E5E7EB') }};">
                                    #{{ $i + 1 }} {{ $s?->name ?? '—' }}
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>

                <flux:select wire:model="paid" label="Pago?">
                    <flux:select.option value="0" label="Não" />
                    <flux:select.option value="1" label="Sim" />
                </flux:select>
                <flux:input label="Concluído em" wire:model="completed_at" type="date" />

                <div class="md:col-span-2">
                    <x-textarea label="Descrição" wire:model="description" rows="3" />
                </div>

                {{-- Se estiver editando, mostra o status atual apenas como leitura (opcional) --}}
                @if ($editingId)
                    <div class="md:col-span-2">
                        <label class="block text-sm mb-1">Status atual</label>
                        <div class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium ring-1 ring-black/10"
                            style="background-color: {{ optional($statusesById->get((int) $current_status_id))->color ?? '#E5E7EB' }};
                           color: {{ contrast_color(optional($statusesById->get((int) $current_status_id))->color ?? '#E5E7EB') }};">
                            {{ optional($statusesById->get((int) $current_status_id))->name ?? '—' }}
                        </div>
                    </div>
                @endif
            </div>

            <div class="flex justify-end items-center mt-2 gap-2">
                <flux:button class="btn-ghost" wire:click="$set('showForm', false)">Cancelar</flux:button>
                <flux:button class="btn-primary" wire:click="save" icon="check">Salvar</flux:button>
            </div>
        </x-modal>


        {{-- Modal Delete --}}
        <x-modal wire:model="confirmingDelete" title="Confirmar exclusão" icon="o-exclamation-triangle" separator>
            <p>Tem certeza que deseja excluir este serviço? Esta ação não pode ser desfeita.</p>
            <x-slot:actions>
                <flux:button class="btn-ghost" wire:click="$set('confirmingDelete', false)">Cancelar</flux:button>
                <flux:button class="btn-error" wire:click="delete" icon="trash">Excluir</flux:button>
            </x-slot:actions>
        </x-modal>
    </div>
</section>
