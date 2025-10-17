<?php

namespace Database\Seeders;

use App\Models\Status;
use Illuminate\Support\Str;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class StatusesSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = [
            ['name' => 'ORÇAMENTO',   'color' => '#B9F2C7'],
            ['name' => 'VENDA',       'color' => '#4C3A2B'],
            ['name' => 'PARADO',      'color' => '#D21E1E'],
            ['name' => 'MORTO',       'color' => '#FF3B3B'],
            ['name' => 'JATO',        'color' => '#2A7187'],
            ['name' => 'MANDRILHAR',  'color' => '#FFA4A4'],
            ['name' => 'SERVIÇO',     'color' => '#D5B8F6'],
            ['name' => 'MONTAGEM',    'color' => '#CBB6F5'],
            ['name' => 'RETÍFICA',    'color' => '#C9C8F6'],
            ['name' => 'PLAINA',      'color' => '#BBD0F4'],
            ['name' => 'TESTE',       'color' => '#BFD8F6'],
            ['name' => 'SOLDA',       'color' => '#5F3B8A'],
            ['name' => 'TRINCADO',    'color' => '#1C56A6'],
            ['name' => 'FINALIZADO',  'color' => '#1C8A4A'],
        ];

        foreach ($statuses as $item) {
            $slug = Str::slug($item['name'], '-');

            Status::updateOrCreate(
                ['slug' => $slug],
                [
                    'name'  => $item['name'],
                    'slug'  => $slug,
                    'color' => $item['color'],
                ]
            );

            // Cria permissão do Spatie para visualizar serviços com este status
            Permission::firstOrCreate(['name' => "services.view.status.{$slug}"]);
        }

        // Permissões gerais (caso ainda não existam)
        foreach (['services.start', 'services.finish', 'services.change-status', 'services.manage'] as $perm) {
            Permission::firstOrCreate(['name' => $perm]);
        }

        // Cria roles padrão
        $adminRole = Role::firstOrCreate(['name' => 'admin']);
        $employeeRole = Role::firstOrCreate(['name' => 'employee']);

        // Admin tem tudo
        $adminRole->syncPermissions(Permission::all());

        // Employee tem apenas iniciar/finalizar por padrão
        $employeeRole->syncPermissions(['services.start', 'services.finish']);
    }
}
