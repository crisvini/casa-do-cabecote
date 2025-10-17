<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $name     = env('ADMIN_NAME');
        $email    = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        // Limpa cache de permissões (importante ao seedar)
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        // Garante que a role 'admin' exista
        $adminRole = Role::firstOrCreate([
            'name'       => 'admin',
            'guard_name' => 'web',
        ]);

        // Dá TODAS as permissões à role admin
        $adminRole->syncPermissions(
            Permission::where('guard_name', 'web')->get()
        );

        // Cria (ou busca) o usuário admin
        $admin = User::firstOrCreate(
            ['email' => $email],
            [
                'name'              => $name,
                'password'          => Hash::make($password)
            ]
        );

        // Garante que o admin tenha a role
        if (! $admin->hasRole('admin')) {
            $admin->assignRole($adminRole);
        }

        // Recarrega cache
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command->info("Admin seeded: {$email} / (password set via ADMIN_PASSWORD)");
    }
}
