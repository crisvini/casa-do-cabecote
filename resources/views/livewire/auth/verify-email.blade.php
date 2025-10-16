<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    /**
     * Send an email verification notification to the user.
     */
    public function sendVerification(): void
    {
        Auth::user()->sendEmailVerificationNotification();

        Session::flash('status', 'verification-link-sent');
    }

    /**
     * Log the current user out of the application.
     */
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect('/', navigate: true);
    }

    /**
     * Handle the component's rendering hook.
     */
    public function rendering(View $view): void
    {
        if (Auth::user()->hasVerifiedEmail()) {
            $this->redirectIntended(default: route('services', absolute: false), navigate: true);

            return;
        }
    }
}; ?>

<div class="mt-4 flex flex-col gap-6">
    <flux:text class="text-center">
        {{ __('Por favor, verifique seu endereço de e-mail clicando no link que acabamos de enviar para você.') }}
    </flux:text>

    @if (session('status') == 'verification-link-sent')
        <flux:text class="text-center font-medium !dark:text-green-400 !text-green-600">
            {{ __('Um novo link de verificação foi enviado para o seu endereço de e-mail.') }}
        </flux:text>
    @endif

    <div class="flex flex-col items-center justify-between space-y-3">
        <flux:button wire:click="sendVerification" variant="primary" class="w-full">
            {{ __('Reenviar e-mail de verificação') }}
        </flux:button>

        <flux:link class="text-sm cursor-pointer" wire:click="logout" data-test="logout-button">
            {{ __('Sair') }}
        </flux:link>
    </div>
</div>
