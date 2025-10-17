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

        <flux:table :paginate="$services">
            <flux:table.columns sticky class="bg-white dark:bg-zinc-900">
                <flux:table.column>#</flux:table.column>
                <flux:table.column>Ordem</flux:table.column>
                <flux:table.column>Cliente</flux:table.column>
                <flux:table.column>Cabeçote</flux:table.column>
                <flux:table.column>Status</flux:table.column>
                <flux:table.column>Pago</flux:table.column>
                <flux:table.column>Concluído em</flux:table.column>
                <flux:table.column align="end">Ações</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @foreach ($services as $row)
                    <flux:table.row :key="$row->id">
                        <flux:table.cell>{{ $row->id }}</flux:table.cell>
                        <flux:table.cell>{{ $row->service_order ?? '—' }}</flux:table.cell>
                        <flux:table.cell>{{ $row->client }}</flux:table.cell>
                        <flux:table.cell>{{ $row->cylinder_head }}</flux:table.cell>

                        <flux:table.cell>
                            @php $st = $row->currentStatus; @endphp
                            <div class="flex items-center gap-2">
                                <flux:badge size="sm" variant="solid" color="zinc">
                                    {{ $st?->name ?? '—' }}
                                </flux:badge>

                                @can('services.change-status')
                                    <flux:dropdown>
                                        <flux:button size="sm" icon:trailing="chevron-down">
                                            Mudar
                                        </flux:button>

                                        <flux:menu>
                                            @foreach ($this->statuses as $opt)
                                                <flux:menu.item
                                                    wire:click="changeStatus({{ $row->id }}, {{ $opt->id }})"
                                                    icon="arrow-right">
                                                    {{ $opt->name }}
                                                </flux:menu.item>
                                            @endforeach
                                        </flux:menu>
                                    </flux:dropdown>
                                @endcan
                            </div>
                        </flux:table.cell>

                        <flux:table.cell>
                            <flux:badge size="sm" variant="solid" :color="$row->paid ? 'green' : 'amber'">
                                {{ $row->paid ? 'Sim' : 'Não' }}
                            </flux:badge>
                        </flux:table.cell>

                        <flux:table.cell>{{ optional($row->completed_at)->format('d/m/Y') ?? '—' }}</flux:table.cell>

                        <flux:table.cell align="end">
                            <div class="flex flex-wrap gap-2 justify-end">
                                @can('services.start')
                                    <flux:button size="sm" icon="play" variant="ghost"
                                        wire:click="startService({{ $row->id }})">Iniciar</flux:button>
                                @endcan
                                @can('services.finish')
                                    <flux:button size="sm" icon="check" color="green"
                                        wire:click="finishService({{ $row->id }})">Finalizar</flux:button>
                                @endcan
                                @can('services.manage')
                                    <flux:button size="sm" icon="pencil-square" variant="ghost"
                                        wire:click="openEdit({{ $row->id }})">Editar</flux:button>
                                    <flux:button size="sm" icon="trash" color="red"
                                        wire:click="confirmDelete({{ $row->id }})">Excluir</flux:button>
                                @endcan
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>


        {{-- Modal Form --}}
        <x-modal wire:model="showForm" title="{{ $editingId ? 'Editar serviço' : 'Novo serviço' }}" separator>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <flux:input label="Cliente" wire:model="client" required />
                <flux:input label="Cabeçote" wire:model="cylinder_head" required />
                <flux:input label="Ordem (opcional)" wire:model="service_order" type="number" min="1" />
                <flux:select label="Status" wire:model="current_status_id" required>
                    @foreach ($this->statuses as $st)
                        <flux:select.option :value="$st->id" :label="$st->name" />
                    @endforeach
                </flux:select>
                <flux:checkbox wire:model="paid" label="Pago" />
                <flux:input label="Concluído em" wire:model="completed_at" type="date" />
                <div class="md:col-span-2">
                    <x-textarea label="Descrição" wire:model="description" rows="3" />
                </div>
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
