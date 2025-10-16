<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return redirect()->route('login');
})->name('home');

Route::view('servicos', 'services')
    ->middleware(['auth', 'verified'])
    ->name('services');

Route::middleware(['auth'])->group(function () {
    Route::redirect('configuracoes', 'configuracoes/perfil');

    Volt::route('configuracoes/perfil', 'settings.profile')->name('profile.edit');
    Volt::route('configuracoes/senha', 'settings.password')->name('password.edit');
    Volt::route('configuracoes/aparencia', 'settings.appearance')->name('appearance.edit');

    Volt::route('usuarios', 'users.index')->name('users.index');
    Volt::route('usuarios/criar', 'users.create')->name('users.create');
    Volt::route('usuarios/editar/{user}', 'users.edit')->name('users.edit');

    // Volt::route('settings/two-factor', 'settings.two-factor')
    //     ->middleware(
    //         when(
    //             Features::canManageTwoFactorAuthentication()
    //                 && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
    //             ['password.confirm'],
    //             [],
    //         ),
    //     )
    //     ->name('two-factor.show');
});

require __DIR__ . '/auth.php';
