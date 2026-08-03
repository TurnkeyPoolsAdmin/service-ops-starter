<?php

use App\Filament\Pages\BookkeepingHealth;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\PlaidItem;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class]);

    $this->admin = Employee::create([
        'name' => 'Admin', 'email' => 'a@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->admin->assignRole('super_admin');

    $this->dispatch = Employee::create([
        'name' => 'Dispatch', 'email' => 'd@test.local', 'can_login' => true, 'active' => true,
        'password' => bcrypt('x'), 'job_functions' => ['office'],
    ]);
    $this->dispatch->assignRole('dispatch');
});

it('renders with both counters at zero when nothing is outstanding', function () {
    Livewire\Livewire::actingAs($this->admin)
        ->test(BookkeepingHealth::class)
        ->assertSuccessful()
        ->assertSee('All clear')
        ->assertSee('All reconciled');
});

it('counts posted transactions still needing review', function () {
    $item = PlaidItem::create(['item_id' => 'i1', 'access_token' => 'a', 'institution_name' => 'Chase']);
    BankTransaction::create([
        'plaid_item_id' => $item->id, 'plaid_transaction_id' => 't1', 'plaid_account_id' => 'acc',
        'amount_cents' => 5000, 'date' => '2026-07-01', 'name' => 'Mystery charge',
        'pending' => false, 'match_status' => BankTransaction::STATUS_UNMATCHED,
    ]);

    $metrics = Livewire\Livewire::actingAs($this->admin)->test(BookkeepingHealth::class)
        ->instance()->getMetrics();

    expect($metrics['transactions']['open'])->toBe(1)
        ->and($metrics['transactions']['total'])->toBe(1);
});

it('counts paid but unconfirmed jobs', function () {
    $customer = Customer::firstOrCreate(['phone_e164' => '+18185550000'], ['first_name' => 'Pat']);
    Job::create([
        'job_number' => 'LA-2026-0001', 'entity_id' => Entity::where('code', 'LA')->value('id'),
        'customer_id' => $customer->id, 'customer_name_snapshot' => 'Pat Lee',
        'status' => 'scheduled', 'amount_collected_cents' => 40000, 'payment_method' => 'zelle',
        'payment_received_at' => now(), // non-cash, unconfirmed
    ]);

    $metrics = Livewire\Livewire::actingAs($this->admin)->test(BookkeepingHealth::class)
        ->instance()->getMetrics();

    expect($metrics['jobs']['open'])->toBe(1);
});

it('is not reachable by dispatch (admin-only finance tool)', function () {
    $this->actingAs($this->admin);
    expect(BookkeepingHealth::canAccess())->toBeTrue();

    $this->actingAs($this->dispatch);
    expect(BookkeepingHealth::canAccess())->toBeFalse();
});
