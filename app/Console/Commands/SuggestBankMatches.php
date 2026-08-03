<?php

namespace App\Console\Commands;

use App\Services\BankMatcher;
use Illuminate\Console\Command;

/**
 * Run the bank-transaction auto-matcher over everything still unmatched.
 * Scheduled daily right after bank:pull-transactions (see routes/console.php);
 * also runs as part of the ledger's "Sync now" button. Idempotent — already
 * suggested/matched transactions are never touched.
 */
class SuggestBankMatches extends Command
{
    protected $signature = 'bank:suggest-matches';

    protected $description = 'Auto-suggest job/tech/misc matches for unmatched bank transactions';

    public function handle(BankMatcher $matcher): int
    {
        $result = $matcher->run();

        $this->info(sprintf(
            '%d auto-matched (misc rules), %d suggested for review.',
            $result['auto'],
            $result['suggested'],
        ));

        return self::SUCCESS;
    }
}
