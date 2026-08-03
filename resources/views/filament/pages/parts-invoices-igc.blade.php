{{--
    IGC branch of the Parts Invoices page — split into its own partial
    (2026-07-28) because a single combined if/supplier/else/endif block
    spanning both branches in one file, compiled through Livewire's
    ExtendBlade Blade-compiler extension, was causing the Mygrant branch's
    content to also attempt to execute on the IGC tab (undefined
    $state/$resolution — Mygrant's own local variables). Splitting into
    two included partials (this file + parts-invoices-mygrant.blade.php)
    verified clean in both directions via a Livewire component test. Keep
    each branch its own file rather than remerging.
--}}
@php
    $igcStatus = $this->igcChargeStatus();
    $igcResolution = $igcStatus['resolution'];
    $igcState = $igcStatus['state'];
@endphp

{{-- IGC status banner --}}
<x-filament::section>
    <div class="flex items-start justify-between gap-4">
        <div>
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Current IGC charge</p>
            <div class="mt-1 flex items-baseline gap-2">
                @if ($igcState === 'none')
                    <span class="text-2xl font-bold text-gray-500 dark:text-gray-400">No IGC charge yet</span>
                @elseif ($igcState === 'matched')
                    <span class="text-3xl font-bold text-success-600 dark:text-success-400">Ties out</span>
                @elseif ($igcState === 'suggested')
                    <span class="text-3xl font-bold text-warning-600 dark:text-warning-400">Needs a confirm</span>
                @elseif ($igcState === 'missing_memo')
                    <span class="text-3xl font-bold text-warning-600 dark:text-warning-400">
                        Missing {{ \App\Support\Money::format((int) $igcResolution->shortfallCents) }}
                    </span>
                @elseif ($igcState === 'credit_applied')
                    <span class="text-3xl font-bold text-info-600 dark:text-info-400">
                        {{ \App\Support\Money::format((int) $igcResolution->overageCents) }} off a credit balance?
                    </span>
                @elseif ($igcState === 'no_group')
                    <span class="text-3xl font-bold text-danger-600 dark:text-danger-400">No payment record</span>
                @else
                    <span class="text-2xl font-bold text-gray-500 dark:text-gray-400">Nothing open</span>
                @endif
            </div>

            @if ($igcResolution)
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                    @if ($igcState === 'no_group')
                        No payment record at all for this charge's date — pull a fresh 21-day export before escalating as a possible duplicate draw or wrong account.
                    @elseif ($igcState === 'missing_memo')
                        A payment memo is probably missing from the report, or the report is stale — re-pull a wider (21-day) window.
                    @elseif ($igcState === 'credit_applied')
                        Check the invoice's Balance column — part of the recorded total may have come off an existing credit balance rather than actually leaving the bank.
                    @elseif ($igcState === 'suggested')
                        A widened-date or AUTH# group was found — confirm it on the Bank Transactions ledger.
                    @elseif ($igcState === 'matched')
                        The last charge ({{ \App\Support\Money::format($igcResolution->chargeCents) }} on {{ $igcResolution->transaction->date }}) matched its payment group exactly.
                    @endif
                </p>
            @endif
        </div>
        <x-filament::icon
            :icon="in_array($igcState, ['matched','none']) ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle'"
            @class([
                'h-8 w-8',
                'text-success-500' => in_array($igcState, ['matched']),
                'text-gray-400' => $igcState === 'none',
                'text-warning-500' => in_array($igcState, ['suggested','missing_memo']),
                'text-info-500' => $igcState === 'credit_applied',
                'text-danger-500' => $igcState === 'no_group',
            ])
        />
    </div>
</x-filament::section>

{{-- Netting + aging summary --}}
@php($netting = $this->igcNettingSummary())
@php($aging = $this->igcAgingDocuments())
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
    <x-filament::section heading="Netting" description="Returns paired to their original invoice (build spec §4).">
        <p style="font-size:1.5rem;font-weight:700;">{{ $netting['paired'] }} paired</p>
        @if ($netting['unpaired'] > 0)
            <p style="font-size:.875rem;color:#d97706;">{{ $netting['unpaired'] }} credit(s) still unpaired — see the Issues queue (IGC - unpaired credit).</p>
        @else
            <p style="font-size:.875rem;color:#94a3b8;">Every return has found its original invoice.</p>
        @endif
    </x-filament::section>

    <x-filament::section heading="Aging" description="Invoices with a balance open more than {{ config('glass.vendors.igc.aging_alert_days', 30) }} days — a look-at, not a failure.">
        @if ($aging->isEmpty())
            <p style="font-size:.875rem;color:#94a3b8;">Nothing aging.</p>
        @else
            <p style="font-size:1.5rem;font-weight:700;">{{ $aging->count() }} invoice(s)</p>
            <p style="font-size:.875rem;color:#d97706;">{{ \App\Support\Money::format((int) $aging->sum('balance_cents')) }} open.</p>
        @endif
    </x-filament::section>
</div>

{{-- IGC documents list --}}
<x-filament::section heading="Documents" description="Most recent IGC invoices and returns.">
    @php($igcDocs = $this->igcDocuments())
    @if ($igcDocs->isEmpty())
        <p style="font-size:.875rem;color:#94a3b8;">No documents yet — upload the invoice history XLSX to get started.</p>
    @else
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.825rem;">
                <thead>
                    <tr style="text-align:left;border-bottom:1px solid rgba(148,163,184,.3);">
                        <th style="padding:.4rem .5rem;">Number</th>
                        <th style="padding:.4rem .5rem;">Type</th>
                        <th style="padding:.4rem .5rem;">Date</th>
                        <th style="padding:.4rem .5rem;text-align:right;">Total</th>
                        <th style="padding:.4rem .5rem;text-align:right;">Balance</th>
                        <th style="padding:.4rem .5rem;">Charge status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($igcDocs as $doc)
                        <tr style="border-bottom:1px solid rgba(148,163,184,.15);">
                            <td style="padding:.4rem .5rem;">{{ $doc->document_number ?? '—' }}</td>
                            <td style="padding:.4rem .5rem;">{{ $doc->type->label() }}</td>
                            <td style="padding:.4rem .5rem;">{{ $doc->document_date }}</td>
                            <td style="padding:.4rem .5rem;text-align:right;">{{ \App\Support\Money::format($doc->net_amount_cents) }}</td>
                            <td style="padding:.4rem .5rem;text-align:right;">{{ $doc->balance_cents === null ? '—' : \App\Support\Money::format($doc->balance_cents) }}</td>
                            <td style="padding:.4rem .5rem;">
                                @if ($doc->charge_matched_at)
                                    <span style="color:#16a34a;">Charged</span>
                                @else
                                    <span style="color:#94a3b8;">Open</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>
