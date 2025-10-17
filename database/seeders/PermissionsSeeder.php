<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class PermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // permissões base (ajuste aos seus status/telas)
        Permission::firstOrCreate(['name' => 'manage users']);
        Permission::firstOrCreate(['name' => 'view all services']);
        Permission::firstOrCreate(['name' => 'view assigned-status services']);

        // roles
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $employee = Role::firstOrCreate(['name' => 'employee']);

        // vincular permissões
        $admin->givePermissionTo(['manage users', 'view all services']);
        $employee->givePermissionTo(['view assigned-status services']);
    }
}
