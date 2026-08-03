<?php

use App\Enums\PaymentMethod;
use App\Filament\Resources\BankTransactions\Tables\BankTransactionsTable;
use App\Models\Customer;
use App\Models\Entity;
use App\Models\Job;
use Database\Seeders\EntitySeeder;

/**
 * The "Payment line(s) this deposit covers" picker gained a job completion
 * date so a human can sanity-check the job's own timeline without opening
 * anything. Uses Job::work_completed_at — when the TECH finished the work —
 * deliberately NOT Job::completed_at, which also requires full payment + tech
 * pay and is therefore still null for many jobs that show up in this exact
 * picker (a payment line can exist before the job is fully reconciled).
 */
function invokeCompletion(string $method, ...$args)
{
    $ref = new ReflectionMethod(BankTransactionsTable::class, $method);
    $ref->setAccessible(true);

    return $ref->invoke(null, ...$args);
}

beforeEach(function () {
    test()->seed(EntitySeeder::class);

    $this->customer = Customer::firstOrCreate(['phone_e164' => '+18185557777'], ['first_name' => 'Test']);
    $this->entityId = Entity::where('code', 'LA')->value('id');
});

function completionJob(array $overrides = []): Job
{
    return Job::create(array_merge([
        'job_number' => 'LA-2026-0107',
        'entity_id' => test()->entityId,
        'customer_id' => test()->customer->id,
        'customer_name_snapshot' => 'Christie Gandara',
        'status' => 'completed',
    ], $overrides));
}

it('shows the job completion date, prefixed to distinguish it from the payment-collected date', function () {
    $job = completionJob(['work_completed_at' => '2026-07-22 18:00:00']);
    $payment = $job->payments()->create([
        'amount_cents' => 55105, 'method' => PaymentMethod::Zelle->value, 'collected_at' => '2026-07-24',
    ]);

    $label = invokeCompletion('paymentLineLabel', $payment->fresh(), null, null);

    // Both dates present and distinguishable - not two bare "Jul N"s.
    expect($label)->toContain('Jul 24')       // payment collected
        ->and($label)->toContain('done Jul 22'); // job completed
});

it('card label carries the completion date too, before Δ', function () {
    $job = completionJob(['work_completed_at' => '2026-07-22 18:00:00']);
    $payment = $job->payments()->create([
        'amount_cents' => 106275, 'method' => PaymentMethod::Stripe->value, 'collected_at' => '2026-07-24',
    ]);

    $label = invokeCompletion('cardLineLabel', $payment->fresh(), 10000);

    expect($label)->toContain('done Jul 22')
        ->and($label)->toContain('· Δ');
    // The completion date must appear BEFORE the delta, not after.
    expect(strpos($label, 'done Jul 22'))->toBeLessThan(strpos($label, '· Δ'));
});

it('shows nothing extra when work_completed_at is null - not a blank " — " bit', function () {
    $job = completionJob(['work_completed_at' => null]);
    $payment = $job->payments()->create([
        'amount_cents' => 55105, 'method' => PaymentMethod::Zelle->value, 'collected_at' => '2026-07-24',
    ]);

    $label = invokeCompletion('paymentLineLabel', $payment->fresh(), null, null);

    expect($label)->not->toContain('done')
        ->and($label)->not->toContain('—  —'); // no empty bit leaking through
});

it('uses work_completed_at, not completed_at, so a payment-recorded-but-not-fully-reconciled job still shows its date', function () {
    // completed_at requires isFullyPaid() + isTechPaid() too - deliberately
    // left null here, exactly the shape of a job that has ONE payment line
    // recorded (what this picker is FOR) but isn't fully closed out yet.
    $job = completionJob(['work_completed_at' => '2026-07-20 17:00:00', 'completed_at' => null]);
    $payment = $job->payments()->create([
        'amount_cents' => 55105, 'method' => PaymentMethod::Zelle->value, 'collected_at' => '2026-07-24',
    ]);

    $label = invokeCompletion('paymentLineLabel', $payment->fresh(), null, null);

    expect($label)->toContain('done Jul 20');
});

it('degrades gracefully when the job is deleted', function () {
    $job = completionJob(['work_completed_at' => '2026-07-22 18:00:00']);
    $payment = $job->payments()->create([
        'amount_cents' => 55105, 'method' => PaymentMethod::Venmo->value, 'collected_at' => '2026-07-24',
    ]);
    $payment->refresh();
    $job->delete();
    $payment->unsetRelation('job');

    $label = invokeCompletion('paymentLineLabel', $payment, null, null);

    expect($label)->not->toContain('done');
});
