<?php

use App\Enums\BankMatchKind;
use App\Models\BankTransaction;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Entity;
use App\Models\Job;
use App\Models\PlaidItem;
use App\Models\Setting;
use App\Services\LlmMatchSuggester;
use Database\Seeders\EntitySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed([RoleSeeder::class, EntitySeeder::class]);
    $this->item = PlaidItem::create(['item_id' => 'i1', 'access_token' => 'a', 'institution_name' => 'Chase']);
    $this->suggester = app(LlmMatchSuggester::class);
});

function enableLlm(): void
{
    Setting::put('llm_matching_enabled', '1', sensitive: false);
    Setting::put('anthropic_api_key', 'sk-ant-test', sensitive: true);
}

function fakeClaude(array $decision): void
{
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($decision)]],
        ]),
    ]);
}

function llmTxn(array $overrides = []): BankTransaction
{
    return BankTransaction::create(array_merge([
        'plaid_item_id' => test()->item->id,
        'plaid_transaction_id' => 'txn-'.fake()->unique()->uuid(),
        'plaid_account_id' => 'acc-1',
        'amount_cents' => 500000,
        'date' => '2026-07-01',
        'name' => 'Zelle payment to someone',
        'pending' => false,
        'match_status' => BankTransaction::STATUS_UNMATCHED,
    ], $overrides));
}

it('is disabled and no-ops until enabled with a key', function () {
    expect($this->suggester->enabled())->toBeFalse();

    $txn = llmTxn();
    expect($this->suggester->suggestFor($txn))->toBeNull();
    Http::assertNothingSent();
});

it('writes an AI-sourced dividend suggestion the human can confirm', function () {
    enableLlm();
    fakeClaude(['kind' => 'dividend', 'owner' => 'pat_owner', 'confidence' => 0.9, 'reason' => 'owner name']);

    $txn = llmTxn(['name' => 'ZELLE TO PAT OWNER']);
    $match = $this->suggester->suggestFor($txn);

    expect($match)->not->toBeNull()
        ->and($match->kind)->toBe(BankMatchKind::Dividend)
        ->and($match->misc_category)->toBe('pat_owner')
        ->and($match->source)->toBe('ai')
        ->and($match->note)->toContain('owner name')
        ->and($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_SUGGESTED);
});

it('maps a job_index back to a real job for a customer payment', function () {
    enableLlm();
    $customer = Customer::firstOrCreate(['phone_e164' => '+18185550000'], ['first_name' => 'Pat']);
    $job = Job::create([
        'job_number' => 'LA-2026-0001', 'entity_id' => Entity::where('code', 'LA')->value('id'),
        'customer_id' => $customer->id, 'customer_name_snapshot' => 'Pat Lee',
        'status' => 'completed', 'amount_collected_cents' => 45000, 'payment_method' => 'zelle',
        'payment_received_at' => '2026-06-30 12:00:00',
    ]);

    fakeClaude(['kind' => 'job_payment', 'job_index' => 0, 'confidence' => 0.8, 'reason' => 'amount + name']);

    $txn = llmTxn(['amount_cents' => -45000, 'name' => 'Zelle from PAT LEE']);
    $match = $this->suggester->suggestFor($txn);

    expect($match->kind)->toBe(BankMatchKind::JobPayment)
        ->and($match->job_id)->toBe($job->id);
});

it('returns null when the model declines (kind = none)', function () {
    enableLlm();
    fakeClaude(['kind' => 'none', 'confidence' => 0.2, 'reason' => 'unclear']);

    $txn = llmTxn();
    expect($this->suggester->suggestFor($txn))->toBeNull()
        ->and($txn->refresh()->match_status)->toBe(BankTransaction::STATUS_UNMATCHED);
});

it('ignores a hallucinated out-of-range index', function () {
    enableLlm();
    fakeClaude(['kind' => 'tech_payout', 'payout_index' => 99, 'confidence' => 0.7, 'reason' => 'guess']);

    $txn = llmTxn();
    expect($this->suggester->suggestFor($txn))->toBeNull();
});

it('never touches an already-matched transaction', function () {
    enableLlm();
    fakeClaude(['kind' => 'misc', 'misc' => 'bank_fee', 'confidence' => 1, 'reason' => 'x']);

    $txn = llmTxn(['match_status' => BankTransaction::STATUS_MATCHED]);
    expect($this->suggester->suggestFor($txn))->toBeNull();
    Http::assertNothingSent();
});
