<?php

use App\Filament\Pages\DataExports;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\WarrantyClaim;
use App\Services\Reporting\CancellationsExport;
use App\Services\Reporting\TechLaborExport;
use App\Support\Csv;
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

    $this->tech = Employee::create([
        'name' => 'Jose Ramirez', 'email' => 't@test.local', 'active' => true,
        'job_functions' => ['technician'], 'fixed_cost_cents' => 12000,
    ]);

    $this->entityId = Entity::where('code', 'LA')->value('id');
});

function exportCustomer(string $phone = '+18185551111', array $attrs = []): Customer
{
    return Customer::create([
        'phone_e164' => $phone,
        'first_name' => $attrs['first_name'] ?? 'Pat',
        'last_name' => $attrs['last_name'] ?? 'Lee',
        ...$attrs,
    ]);
}

function exportJob(array $attrs = []): Job
{
    static $n = 0;
    $n++;

    $customerId = $attrs['customer_id'] ?? Customer::firstOrCreate(
        ['phone_e164' => '+18185551111'],
        ['first_name' => 'Pat', 'last_name' => 'Lee'],
    )->id;

    return Job::create([
        'job_number' => sprintf('LA-2026-%04d', $n),
        'entity_id' => Entity::where('code', 'LA')->value('id'),
        'customer_id' => $customerId,
        'customer_name_snapshot' => 'Pat Lee',
        'customer_phone_snapshot' => '+18185551111',
        'status' => 'pending_schedule',
        ...$attrs,
    ]);
}

// ---- Cancellations ----

it('exports canceled jobs and lost leads together, each with its own reason', function () {
    exportJob([
        'status' => 'canceled', 'canceled_at' => now(),
        'cancellation_reason' => 'Customer went elsewhere',
        'vehicle_year' => '2019', 'vehicle_make' => 'Toyota', 'vehicle_model' => 'Camry',
    ]);
    exportJob([
        'status' => 'lost', 'canceled_at' => now(),
        'lost_reason' => 'Quote too high',
    ]);

    $rows = (new CancellationsExport)->rows();

    expect($rows)->toHaveCount(2);

    $reasons = array_column($rows, 2);
    expect($reasons)->toContain('Customer went elsewhere')
        ->and($reasons)->toContain('Quote too high');

    // Status distinguishes the two so they can be split in Excel.
    expect(array_column($rows, 1))->toContain('Canceled')->toContain('Lost');
});

it('leaves open and completed jobs out of the cancellations export', function () {
    exportJob(['status' => 'scheduled']);
    exportJob(['status' => 'completed']);
    exportJob(['status' => 'canceled', 'canceled_at' => now()]);

    expect((new CancellationsExport)->rows())->toHaveCount(1);
});

it('excludes test jobs from the cancellations export', function () {
    exportJob(['status' => 'canceled', 'canceled_at' => now(), 'is_test' => true]);

    expect((new CancellationsExport)->rows())->toBeEmpty();
});

it('carries the quo conversation link and flags money that has to go back', function () {
    $customer = exportCustomer('+18185552222', [
        'quo_conversation_url' => 'https://my.quo.com/inbox/PN1/c/CN9',
    ]);

    exportJob([
        'status' => 'canceled', 'canceled_at' => now(), 'customer_id' => $customer->id,
        'amount_collected_cents' => 25000, 'payment_method' => 'zelle', 'payment_received_at' => now(),
    ]);

    $row = (new CancellationsExport)->rows()[0];
    $header = (new CancellationsExport)->header();

    $quo = array_search('Quo conversation link', $header, true);
    $refund = array_search('Refund owed', $header, true);
    $collected = array_search('Amount collected', $header, true);

    expect($row[$quo])->toBe('https://my.quo.com/inbox/PN1/c/CN9')
        ->and($row[$refund])->toBeTrue()
        ->and($row[$collected])->toBe('250.00');
});

it('reports no refund owed when nothing was ever collected', function () {
    exportJob(['status' => 'canceled', 'canceled_at' => now()]);

    $header = (new CancellationsExport)->header();
    $refund = array_search('Refund owed', $header, true);

    expect((new CancellationsExport)->rows()[0][$refund])->toBeFalse();
});

// ---- Tech labor ----

it('exports the four columns asked for: job, car type, technician and amount', function () {
    $job = exportJob([
        'status' => 'completed', 'technician_id' => $this->tech->id,
        'work_completed_at' => now(), 'tech_paid_at' => now(),
        'tech_pay_amount_cents' => 12000,
        'vehicle_year' => '2019', 'vehicle_make' => 'Toyota', 'vehicle_model' => 'Camry',
    ]);

    $row = (new TechLaborExport)->rows()[0];

    expect($row[0])->toBe($job->job_number)
        ->and($row[1])->toBe('2019 Toyota Camry')
        ->and($row[2])->toBe('Jose Ramirez')
        ->and($row[3])->toBe('120.00')
        ->and($row[4])->toBe('Paid');
});

it('includes additional labor in the amount before the day is settled', function () {
    // Pre-settlement the extra pay sits in its own column; reading the raw
    // tech_pay_amount_cents would under-report this tech by $50.
    exportJob([
        'status' => 'work_completed', 'technician_id' => $this->tech->id,
        'work_completed_at' => now(),
        'tech_pay_amount_cents' => 12000, 'additional_labor_cents' => 5000,
        'additional_labor_reason' => 'Extra trim work',
    ]);

    $row = (new TechLaborExport)->rows()[0];

    expect($row[3])->toBe('170.00')
        ->and($row[4])->toBe('Not yet paid');
});

it('keeps tech-fronted parts out of the labor amount', function () {
    exportJob([
        'status' => 'completed', 'technician_id' => $this->tech->id,
        'work_completed_at' => now(), 'tech_paid_at' => now(),
        'tech_pay_amount_cents' => 12000, 'tech_parts_cost_cents' => 8000,
    ]);

    $header = (new TechLaborExport)->header();
    $row = (new TechLaborExport)->rows()[0];

    $parts = array_search('Parts fronted by tech', $header, true);
    $total = array_search('Total to tech', $header, true);

    // Labor stays 120.00; the reimbursement is reported beside it, not inside it.
    expect($row[3])->toBe('120.00')
        ->and($row[$parts])->toBe('80.00')
        ->and($row[$total])->toBe('200.00');
});

it('adds a row for paid warranty visits so per-tech totals are complete', function () {
    $job = exportJob([
        'status' => 'completed', 'technician_id' => $this->tech->id,
        'work_completed_at' => now(), 'tech_paid_at' => now(), 'tech_pay_amount_cents' => 12000,
        'vehicle_year' => '2019', 'vehicle_make' => 'Toyota', 'vehicle_model' => 'Camry',
    ]);

    WarrantyClaim::create([
        'job_id' => $job->id, 'customer_id' => $job->customer_id, 'entity_id' => $job->entity_id,
        'status' => WarrantyClaim::STATUS_RESOLVED, 'issue' => 'Leak',
        'technician_id' => $this->tech->id, 'tech_pay_cents' => 4000,
        'resolved_at' => now(),
    ]);

    $rows = (new TechLaborExport)->rows();
    $header = (new TechLaborExport)->header();
    $type = array_search('Type', $header, true);

    expect($rows)->toHaveCount(2);

    $warranty = collect($rows)->firstWhere($type, 'Warranty visit');

    expect($warranty[2])->toBe('Jose Ramirez')
        ->and($warranty[3])->toBe('40.00')
        // The visit earns no revenue, so the job's money must not repeat here.
        ->and($warranty[array_search('Quote', $header, true)])->toBeNull();
});

it('leaves unpaid warranty visits out entirely', function () {
    $job = exportJob([
        'status' => 'completed', 'technician_id' => $this->tech->id, 'work_completed_at' => now(),
    ]);

    WarrantyClaim::create([
        'job_id' => $job->id, 'customer_id' => $job->customer_id, 'entity_id' => $job->entity_id,
        'status' => WarrantyClaim::STATUS_RESOLVED, 'issue' => 'Leak',
        'technician_id' => $this->tech->id, 'tech_pay_cents' => 0, 'resolved_at' => now(),
    ]);

    expect((new TechLaborExport)->rows())->toHaveCount(1);
});

it('leaves canceled and unworked jobs out of the labor export', function () {
    exportJob(['status' => 'canceled', 'canceled_at' => now(), 'technician_id' => $this->tech->id]);
    exportJob(['status' => 'scheduled', 'technician_id' => $this->tech->id]);

    expect((new TechLaborExport)->rows())->toBeEmpty();
});

// ---- CSV shape ----

it('writes a BOM and plain numeric money so Excel reads and sums the sheet', function () {
    exportJob([
        'status' => 'canceled', 'canceled_at' => now(),
        'cancellation_reason' => 'Customer changed mind',
        'quote_amount_cents' => 45000,
    ]);

    $csv = (new CancellationsExport)->toCsv();

    expect($csv)->toStartWith(Csv::BOM)
        ->and($csv)->toContain('Customer changed mind')
        // No currency symbol — the cell must be a number Excel can total.
        ->and($csv)->toContain('450.00')
        ->and($csv)->not->toContain('$450.00');
});

it('renders booleans as Yes and No rather than 1 and 0', function () {
    expect(Csv::build(['A'], [[true], [false], [null]]))
        ->toContain("Yes\n")->toContain("No\n");
});

// ---- Page access ----

it('lets an admin open the page and see both data sets', function () {
    exportJob(['status' => 'canceled', 'canceled_at' => now()]);

    Livewire\Livewire::actingAs($this->admin)
        ->test(DataExports::class)
        ->assertSuccessful()
        ->assertSee('Cancellations')
        ->assertSee('Tech labor');
});

it('hides the export page from dispatch', function () {
    expect(DataExports::canAccess())->toBeFalse();

    $this->actingAs($this->dispatch);
    expect(DataExports::canAccess())->toBeFalse();

    $this->actingAs($this->admin);
    expect(DataExports::canAccess())->toBeTrue();
});

it('streams a csv download from the page action', function () {
    exportJob(['status' => 'canceled', 'canceled_at' => now()]);

    Livewire\Livewire::actingAs($this->admin)
        ->test(DataExports::class)
        ->callAction('downloadCancellations')
        ->assertFileDownloaded();
});

it('writes both csv files from the artisan command', function () {
    exportJob(['status' => 'canceled', 'canceled_at' => now(), 'cancellation_reason' => 'Changed mind']);
    exportJob([
        'status' => 'completed', 'technician_id' => $this->tech->id,
        'work_completed_at' => now(), 'tech_paid_at' => now(), 'tech_pay_amount_cents' => 12000,
    ]);

    $dir = sys_get_temp_dir().'/exports-'.uniqid();
    mkdir($dir);

    $this->artisan('reports:export', ['dataset' => 'all', '--path' => $dir])
        ->assertSuccessful();

    $files = array_values(array_diff(scandir($dir), ['.', '..']));

    expect($files)->toHaveCount(2)
        ->and(implode(' ', $files))->toContain('cancellations')
        ->and(implode(' ', $files))->toContain('tech-labor')
        ->and(file_get_contents($dir.'/'.$files[0]))->toStartWith(Csv::BOM);

    array_map(fn ($f) => unlink($dir.'/'.$f), $files);
    rmdir($dir);
});

it('rejects an unknown data set name', function () {
    $this->artisan('reports:export', ['dataset' => 'nonsense'])->assertFailed();
});
