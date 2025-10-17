<div class="space-y-4" wire:poll.15s> {{-- atualização simples a cada 15s --}}
    <div class="flex flex-wrap items-center gap-3">
        {{-- <x-input wire:model.live.debounce.400ms="search" icon="o-magnifying-glass"
            placeholder="Buscar por cliente, cabeçote ou ordem..." class="w-full md:w-1/3" /> --}}

        <x-select wire:model.live="statusFilter" placeholder="Filtrar status" class="w-full md:w-56">
            <x-select.option :value="null" label="Todos" />
            @foreach ($this->statuses as $st)
                <x-select.option :value="$st->id" :label="$st->name" />
            @endforeach
        </x-select>

        <x-select wire:model.live="paidFilter" placeholder="Pago?" class="w-full md:w-40">
            <x-select.option :value="null" label="Todos" />
            <x-select.option value="1" label="Pago" />
            <x-select.option value="0" label="Em aberto" />
        </x-select>

        @can('services.manage')
            {{-- <x-button icon="o-plus" class="btn-primary ml-auto" wire:click="openCreate">
                Novo serviço
            </x-button> --}}
        @endcan
    </div>

    @php
        $headers = [
            // It calls PHP Carbon::parse($value)->format($pattern)
            ['key' => 'created_at', 'label' => 'Date', 'format' => ['date', 'd/m/Y']],

            // It calls number_format()
            // The first parameter represents all parameters in order for `number_format()` function
            // The  second parameter is any string to prepend (optional)
            ['key' => 'salary', 'label' => 'Salary', 'format' => ['currency', '2,.', 'R$ ']],

            // A closure that has the current row and field value itself
            ['key' => 'is_employee', 'label' => 'Employee?', 'format' => fn($row, $field) => $field ? 'Yes' : 'No'],
        ];
    @endphp

    {{-- <x-table :rows="$services" :with-pagination="true">
        <x-slot:cols>
            <x-col label="#" />
            <x-col label="Ordem" />
            <x-col label="Cliente" />
            <x-col label="Cabeçote" />
            <x-col label="Status" />
            <x-col label="Pago" />
            <x-col label="Concluído em" />
            <x-col label="Ações" />
        </x-slot:cols>

        @foreach ($services as $row)
            <x-row>
                <x-cell>{{ $row->id }}</x-cell>
                <x-cell>{{ $row->service_order ?? '—' }}</x-cell>
                <x-cell>{{ $row->client }}</x-cell>
                <x-cell>{{ $row->cylinder_head }}</x-cell>

                <x-cell>
                    <div class="flex items-center gap-2">
                        @php
                            $st = $row->currentStatus;
                        @endphp
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs"
                            style="background: {{ $st?->color ?? '#E5E7EB' }}; color:#111;">
                            {{ $st?->name ?? '—' }}
                        </span>

                        @can('services.change-status')
                            <x-dropdown label="Mudar">
                                @foreach ($this->statuses as $opt)
                                    <x-dropdown.item wire:click="changeStatus({{ $row->id }}, {{ $opt->id }})">
                                        {{ $opt->name }}
                                    </x-dropdown.item>
                                @endforeach
                            </x-dropdown>
                        @endcan
                    </div>
                </x-cell>

                <x-cell>
                    <x-badge :value="$row->paid ? 'Sim' : 'Não'" :class="$row->paid ? 'badge-success' : 'badge-warning'" />
                </x-cell>

                <x-cell>{{ optional($row->completed_at)->format('d/m/Y') ?? '—' }}</x-cell>

                <x-cell>
                    <div class="flex flex-wrap gap-2">
                        @can('services.start')
                            <x-button size="sm" wire:click="startService({{ $row->id }})" icon="o-play"
                                class="btn-outline">Iniciar</x-button>
                        @endcan

                        @can('services.finish')
                            <x-button size="sm" wire:click="finishService({{ $row->id }})" icon="o-check"
                                class="btn-success">Finalizar</x-button>
                        @endcan

                        @can('services.manage')
                            <x-button size="sm" wire:click="openEdit({{ $row->id }})" icon="o-pencil-square"
                                class="btn-ghost">Editar</x-button>
                            <x-button size="sm" wire:click="confirmDelete({{ $row->id }})" icon="o-trash"
                                class="btn-error">Excluir</x-button>
                        @endcan
                    </div>
                </x-cell>
            </x-row>
        @endforeach
    </x-table> --}}

    {{-- Modal Form --}}
    <x-modal wire:model="showForm" title="{{ $editingId ? 'Editar serviço' : 'Novo serviço' }}" separator>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <x-input label="Cliente" wire:model="client" required />
            <x-input label="Cabeçote" wire:model="cylinder_head" required />
            <x-input label="Ordem (opcional)" wire:model="service_order" type="number" min="1" />
            <x-select label="Status" wire:model="current_status_id" required>
                @foreach ($this->statuses as $st)
                    <x-select.option :value="$st->id" :label="$st->name" />
                @endforeach
            </x-select>
            {{-- <x-toggle wire:model="paid" label="Pago" /> --}}
            <x-input label="Concluído em" wire:model="completed_at" type="date" />
            <div class="md:col-span-2">
                <x-textarea label="Descrição" wire:model="description" rows="3" />
            </div>
        </div>

        <x-slot:actions>
            <x-button class="btn-ghost" wire:click="$set('showForm', false)">Cancelar</x-button>
            {{-- <x-button class="btn-primary" wire:click="save" icon="o-check">Salvar</x-button> --}}
        </x-slot:actions>
    </x-modal>

    {{-- Modal Delete --}}
    <x-modal wire:model="confirmingDelete" title="Confirmar exclusão" icon="o-exclamation-triangle" separator>
        <p>Tem certeza que deseja excluir este serviço? Esta ação não pode ser desfeita.</p>
        <x-slot:actions>
            <x-button class="btn-ghost" wire:click="$set('confirmingDelete', false)">Cancelar</x-button>
            {{-- <x-button class="btn-error" wire:click="delete" icon="o-trash">Excluir</x-button> --}}
        </x-slot:actions>
    </x-modal>
</div>
