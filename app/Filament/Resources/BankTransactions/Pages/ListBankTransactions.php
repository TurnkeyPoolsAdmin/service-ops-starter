<?php

namespace App\Filament\Resources\BankTransactions\Pages;

use App\Filament\Resources\BankTransactions\BankTransactionResource;
use App\Filament\Widgets\BankBalancesOverview;
use App\Models\BankTransaction;
use App\Models\PlaidItem;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;

class ListBankTransactions extends ListRecords
{
    protected static string $resource = BankTransactionResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            BankBalancesOverview::class,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')
                ->label('Sync now')
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn () => PlaidItem::query()->exists())
                ->action(function () {
                    $before = BankTransaction::count();
                    Artisan::call('bank:pull-transactions');
                    $new = BankTransaction::count() - $before;

                    // Propose matches for whatever just landed (and any backlog).
                    Artisan::call('bank:suggest-matches');

                    Notification::make()
                        ->title('Bank sync complete')
                        ->body($new > 0
                            ? "{$new} new transaction(s) pulled."
                            : 'No new transactions — already up to date.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * The weekly reconciliation queue: work "Needs review" down to zero (confirm
     * the suggestions, manually match or categorize the rest), and every Chase
     * transaction is accounted for.
     */
    public function getTabs(): array
    {
        return [
            // NB: the closure param MUST be named $query — Filament injects by
            // parameter name, and $q resolves to nothing (see CLAUDE.md decoder).
            'needs_review' => Tab::make('Needs review')
                ->modifyQueryUsing(fn (Builder $query) => $query->needsReview())
                ->badge(BankTransaction::needsReview()->count())
                ->badgeColor(BankTransaction::needsReview()->exists() ? 'warning' : 'success'),

            'matched' => Tab::make('Matched')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('match_status', BankTransaction::STATUS_MATCHED)),

            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'needs_review';
    }
}
