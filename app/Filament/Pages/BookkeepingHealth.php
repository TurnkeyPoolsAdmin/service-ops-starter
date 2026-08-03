<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GatedByPermission;
use App\Filament\Resources\BankTransactions\BankTransactionResource;
use App\Filament\Resources\Jobs\JobResource;
use App\Models\BankTransaction;
use App\Models\Job;
use App\Models\VendorDocumentLine;
use App\Services\Vendors\VendorChargeReconciler;
use App\Support\Money;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Bookkeeping health — the "is everything accounted for?" scoreboard. Three
 * counters must all reach zero for the books to be closed:
 *   1. every posted Chase transaction is categorized (matched),
 *   2. every job with a recorded (non-cash) payment is verified received, and
 *   3. every Mygrant charge ties out and every billed line has a job.
 * Read-only; each card links to the tool that clears it. Reached via the Admin
 * > Finance hub, gated access_admin (admin + super_admin).
 */
class BookkeepingHealth extends Page
{
    use GatedByPermission;

    protected static function requiredPermission(): string
    {
        return 'access_admin';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    protected string $view = 'filament.pages.bookkeeping-health';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $title = 'Bookkeeping Health';

    protected static ?string $navigationLabel = 'Bookkeeping Health';

    protected static ?int $navigationSort = 26;

    public function getSubheading(): ?string
    {
        return 'Every Chase transaction accounted for, every paid job reconciled. Both counters hit zero = the books are square.';
    }

    /** The two headline counters + their drill-down links, computed live. */
    public function getMetrics(): array
    {
        $postedTotal = BankTransaction::query()->where('pending', false)->count();
        $needsReview = BankTransaction::query()->needsReview()->count();

        $unconfirmedJobs = Job::query()->collectedUnconfirmed()->count();
        $awaiting = Job::query()->awaitingPayment()->get();

        $mygrantLines = VendorDocumentLine::query()->needsJob()->count();
        $mygrantCharge = app(VendorChargeReconciler::class)->latestChargeResolution();
        $mygrantChargeOpen = $mygrantCharge?->needsAttention() ?? false;
        $mygrantOpen = $mygrantLines + ($mygrantChargeOpen ? 1 : 0);

        return [
            'transactions' => [
                'label' => 'Transactions to account for',
                'open' => $needsReview,
                'done' => $postedTotal - $needsReview,
                'total' => $postedTotal,
                'caption' => $needsReview === 0
                    ? 'Every posted Chase transaction is categorized.'
                    : $needsReview.' posted '.str('transaction')->plural($needsReview).' still need a match.',
                'url' => BankTransactionResource::getUrl(),
                'cta' => 'Open Bank Transactions',
            ],
            'jobs' => [
                'label' => 'Paid jobs to reconcile',
                'open' => $unconfirmedJobs,
                'caption' => $unconfirmedJobs === 0
                    ? 'Every recorded non-cash payment is verified received.'
                    : $unconfirmedJobs.' paid '.str('job')->plural($unconfirmedJobs).' await a matched/confirmed deposit. '
                        .'Match the deposit in the ledger, or use "Confirm payment received" on the job.',
                'url' => JobResource::getUrl(parameters: ['activeTab' => 'paid']),
                'cta' => 'Open paid jobs',
            ],
            'mygrant' => [
                'label' => 'Mygrant parts to reconcile',
                'open' => $mygrantOpen,
                'caption' => $mygrantOpen === 0
                    ? 'Every Mygrant charge ties out and every billed line has a job.'
                    : ($mygrantLines > 0 ? $mygrantLines.' billed '.str('line')->plural($mygrantLines).' need a job. ' : '')
                        .($mygrantChargeOpen ? 'The latest charge needs attention.' : ''),
                'url' => PartsInvoices::getUrl(),
                'cta' => 'Open Parts Invoices',
            ],
            // Informational — money still owed to us (not a reconciliation gap).
            'owed' => [
                'label' => 'Money still owed',
                'open' => $awaiting->count(),
                'amount' => Money::format((int) $awaiting->sum(fn (Job $j) => $j->balanceDueCents() ?: (int) ($j->quote_amount_cents ?? 0))),
                'url' => JobResource::getUrl(parameters: ['activeTab' => 'awaiting_payment']),
                'cta' => 'Open unpaid jobs',
            ],
        ];
    }
}
