<?php

use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::middleware('guest')->group(function () {
    Volt::route('login', 'auth.login')->name('login');

    // Volt::route('register', 'auth.register')
    //     ->name('register');

    Volt::route('esqueci-minha-senha', 'auth.forgot-password')->name('password.request');

    Volt::route('redefinir-senha/{token}', 'auth.reset-password')->name('password.reset');

});

Route::middleware('auth')->group(function () {
    Volt::route('verificar-email', 'auth.verify-email')->name('verification.notice');

    Route::get('verificar-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
});

Route::post('logout', App\Livewire\Actions\Logout::class)->name('logout');
