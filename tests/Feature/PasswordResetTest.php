<?php

use App\Models\Employee;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = Employee::create([
        'name' => 'Ops', 'email' => 'op@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
});

it('exposes the request-password-reset page on the admin panel', function () {
    $this->get(route('filament.admin.auth.password-reset.request'))->assertSuccessful();
});

it('emails a reset link to a login-enabled employee', function () {
    Notification::fake();

    Livewire::test(RequestPasswordReset::class)
        ->fillForm(['email' => 'op@test.local'])
        ->call('request');

    Notification::assertSentTo($this->user, ResetPasswordNotification::class);
});

it('does not send a reset link to an employee who cannot log in', function () {
    Notification::fake();

    $noLogin = Employee::create([
        'name' => 'No Login', 'email' => 'nologin@test.local', 'can_login' => false, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['technician'],
    ]);

    Livewire::test(RequestPasswordReset::class)
        ->fillForm(['email' => 'nologin@test.local'])
        ->call('request');

    Notification::assertNothingSentTo($noLogin);
});
