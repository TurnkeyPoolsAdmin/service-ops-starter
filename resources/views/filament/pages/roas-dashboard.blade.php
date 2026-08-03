<x-filament-panels::page>
    @php
        $money = fn (?int $cents) => \App\Support\Money::format($cents);
        $leadsFmt = fn (float $n) => rtrim(rtrim(number_format($n, 1), '0'), '.');
        $cpl = fn (\App\Services\Reporting\RoasMetrics $m) => $m->costPerLeadCents() === null ? '—' : \App\Support\Money::format($m->costPerLeadCents());
        $roasFmt = fn (\App\Services\Reporting\RoasMetrics $m) => $m->roas() === null ? '—' : number_format($m->roas(), 2) . '×';

        $report = $this->report();
        $grand = $report['grand'];
    @endphp

    {{-- Filters --}}
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    {{-- Grand totals for the selected range --}}
    <div class="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6">
        @php
            $cards = [
                ['Ad spend', $money($grand->spendCents), 'text-gray-950 dark:text-white'],
                ['Leads (conversions)', $leadsFmt($grand->leads), 'text-gray-950 dark:text-white'],
                ['Cost / lead', $cpl($grand), 'text-gray-950 dark:text-white'],
                ['Revenue booked', $money($grand->revenueCents), 'text-gray-950 dark:text-white'],
                ['Booked profit', $money($grand->profitCents), 'text-gray-950 dark:text-white'],
                ['ROAS', $roasFmt($grand), $grand->roas() !== null && $grand->roas() >= 1 ? 'text-success-600 dark:text-success-400' : 'text-gray-950 dark:text-white'],
            ];
        @endphp
        @foreach ($cards as [$label, $value, $valueClass])
            <x-filament::section class="!p-0">
                <div class="p-4">
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                    <div class="mt-1 text-2xl font-semibold {{ $valueClass }}">{{ $value }}</div>
                </div>
            </x-filament::section>
        @endforeach
    </div>

    <p class="text-sm text-gray-500 dark:text-gray-400">
        Spend &amp; leads come from Google Ads (one account per location). Revenue is the quoted amount of jobs booked in the
        period and profit is quote minus part cost minus tech payout (expected, even if not yet collected), attributed to the
        week each job was booked. Lost/canceled jobs are excluded. Net profit subtracts ad spend.
        Totals reflect the selected date range and locations ({{ \App\Support\Timezone::display() }}).
        @php $lastSync = $this->lastSyncedAt(); @endphp
        @if ($lastSync)
            <span class="block mt-1">Google Ads last refreshed: <span class="font-medium text-gray-700 dark:text-gray-300">{{ $lastSync }}</span> · refreshes automatically each morning, or use <span class="font-medium">Refresh from Google Ads</span> above.</span>
        @else
            <span class="block mt-1 text-warning-600 dark:text-warning-400">Google Ads has never been pulled. Use <span class="font-medium">Refresh from Google Ads</span> above, or wait for the morning sync.</span>
        @endif
    </p>

</x-filament-panels::page>
