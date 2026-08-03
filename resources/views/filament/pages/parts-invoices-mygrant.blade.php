{{--
    Mygrant branch of the Parts Invoices page — split out of the combined
    if/supplier/else/endif block for the same reason as
    parts-invoices-igc.blade.php's header comment explains. Keep this its
    own file rather than remerging into a single-file if/else.
--}}
@php
    $status = $this->chargeStatus();
    $resolution = $status['resolution'];
    $state = $status['state'];
@endphp

{{-- Status banner --}}
<x-filament::section>
    <div class="flex items-start justify-between gap-4">
        <div>
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Current Mygrant charge</p>
            <div class="mt-1 flex items-baseline gap-2">
                @if ($state === 'none')
                    <span class="text-2xl font-bold text-gray-500 dark:text-gray-400">No Mygrant charge yet</span>
                @elseif ($state === 'matched')
                    <span class="text-3xl font-bold text-success-600 dark:text-success-400">Ties out</span>
                @elseif ($state === 'suggested')
                    <span class="text-3xl font-bold text-warning-600 dark:text-warning-400">Needs a confirm</span>
                @elseif ($state === 'short')
                    <span class="text-3xl font-bold text-warning-600 dark:text-warning-400">
                        Short {{ \App\Support\Money::format((int) $resolution->shortfallCents) }}
                    </span>
                @elseif ($state === 'over')
                    <span class="text-3xl font-bold text-danger-600 dark:text-danger-400">
                        Over by {{ \App\Support\Money::format((int) $resolution->overageCents) }}
                    </span>
                @elseif ($state === 'ambiguous')
                    <span class="text-3xl font-bold text-danger-600 dark:text-danger-400">Ambiguous match</span>
                @elseif ($state === 'unresolved')
                    <span class="text-3xl font-bold text-danger-600 dark:text-danger-400">Needs review</span>
                @else
                    <span class="text-2xl font-bold text-gray-500 dark:text-gray-400">Nothing open</span>
                @endif
            </div>

            @if ($resolution)
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                    @if ($state === 'short')
                        Download the credit memo for the shortfall above — try the invoice number + 1 on Mygrant's site. This is THIS WEEK'S own shortfall, not the backlog.
                    @elseif ($state === 'over')
                        The charge is more than this week's own document(s) add up to — likely a missing invoice, or a sibling ShipTo account. This never auto-resolves.
                    @elseif ($state === 'ambiguous')
                        More than one combination of documents sums to this charge — pick the right one by hand on the Bank Transactions ledger.
                    @elseif ($state === 'suggested')
                        A unique combination of documents was found — confirm it on the Bank Transactions ledger.
                    @elseif ($state === 'matched')
                        The last charge ({{ \App\Support\Money::format($resolution->chargeCents) }} on {{ $resolution->transaction->date }}) matched its own week's document(s) exactly.
                    @elseif ($state === 'unresolved')
                        No document shares this charge's own settle date, and nothing in the backlog resolves it either — check by hand below.
                    @endif
                </p>
            @endif
        </div>
        <x-filament::icon
            :icon="in_array($state, ['matched','none']) ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle'"
            @class([
                'h-8 w-8',
                'text-success-500' => in_array($state, ['matched']),
                'text-gray-400' => $state === 'none',
                'text-warning-500' => in_array($state, ['short','suggested']),
                'text-danger-500' => in_array($state, ['over','ambiguous','unresolved']),
            ])
        />
    </div>
</x-filament::section>

{{-- Predates bank data: documents with no charge and no future charge to expect --}}
@php
    $outOfScope = $this->outOfScopeDocuments();
    $outOfScopeIds = $outOfScope->pluck('id');
@endphp
@if ($outOfScope->isNotEmpty())
    <x-filament::section heading="Predates bank data" description="These settle before the bank ledger's own Mygrant coverage started — no Chase charge will ever cover them, so they're excluded from every reconciliation. Explained here, not hidden.">
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.825rem;">
                <thead>
                    <tr style="text-align:left;border-bottom:1px solid rgba(148,163,184,.3);">
                        <th style="padding:.4rem .5rem;">Number</th>
                        <th style="padding:.4rem .5rem;">Settle date</th>
                        <th style="padding:.4rem .5rem;text-align:right;">Net</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($outOfScope as $doc)
                        <tr style="border-bottom:1px solid rgba(148,163,184,.15);">
                            <td style="padding:.4rem .5rem;">{{ $doc->document_number ?? 'Open period' }}</td>
                            <td style="padding:.4rem .5rem;">{{ $doc->settle_date }}</td>
                            <td style="padding:.4rem .5rem;text-align:right;">{{ \App\Support\Money::format($doc->net_amount_cents) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
@endif

{{-- Documents list --}}
<x-filament::section heading="Documents" description="Most recent Mygrant invoices and credit memos.">
    @php($docs = $this->documents())
    @if ($docs->isEmpty())
        <p style="font-size:.875rem;color:#94a3b8;">No documents yet — upload the order history CSV to get started.</p>
    @else
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.825rem;">
                <thead>
                    <tr style="text-align:left;border-bottom:1px solid rgba(148,163,184,.3);">
                        <th style="padding:.4rem .5rem;">Number</th>
                        <th style="padding:.4rem .5rem;">Type</th>
                        <th style="padding:.4rem .5rem;">Cut date</th>
                        <th style="padding:.4rem .5rem;">Settle date</th>
                        <th style="padding:.4rem .5rem;text-align:right;">Net</th>
                        <th style="padding:.4rem .5rem;text-align:right;">Lines</th>
                        <th style="padding:.4rem .5rem;">Charge status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($docs as $doc)
                        <tr style="border-bottom:1px solid rgba(148,163,184,.15);">
                            <td style="padding:.4rem .5rem;">{{ $doc->document_number ?? 'Open period' }}</td>
                            <td style="padding:.4rem .5rem;">{{ $doc->type->label() }}</td>
                            <td style="padding:.4rem .5rem;">{{ $doc->cut_date }}</td>
                            <td style="padding:.4rem .5rem;">{{ $doc->settle_date }}</td>
                            <td style="padding:.4rem .5rem;text-align:right;">{{ \App\Support\Money::format($doc->net_amount_cents) }}</td>
                            <td style="padding:.4rem .5rem;text-align:right;">{{ $doc->line_count }}</td>
                            <td style="padding:.4rem .5rem;">
                                @if ($doc->charge_matched_at)
                                    <span style="color:#16a34a;">Charged</span>
                                @elseif ($doc->hasAcceptedDifference())
                                    <span style="color:#d97706;">Accepted diff</span>
                                @elseif ($outOfScopeIds->contains($doc->id))
                                    <span style="color:#94a3b8;font-style:italic;">Predates bank data</span>
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

{{-- Lines to match: rendered unconditionally in parts-invoices.blade.php
     (always, on both tabs, just hidden via CSS on this one) — see that
     file's comment for why. --}}

{{-- Variances --}}
<x-filament::section heading="Variances" description="Matched lines where the invoice amount differs from the job's recorded part cost.">
    @php($variances = $this->variances())
    @if ($variances->isEmpty())
        <p style="font-size:.875rem;color:#94a3b8;">No variances — every matched line agrees with its job's recorded part cost.</p>
    @else
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.825rem;">
                <thead>
                    <tr style="text-align:left;border-bottom:1px solid rgba(148,163,184,.3);">
                        <th style="padding:.4rem .5rem;">Job</th>
                        <th style="padding:.4rem .5rem;">Part</th>
                        <th style="padding:.4rem .5rem;text-align:right;">Invoice amount</th>
                        <th style="padding:.4rem .5rem;text-align:right;">Job's recorded cost</th>
                        <th style="padding:.4rem .5rem;text-align:right;">Variance</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($variances as $line)
                        <tr style="border-bottom:1px solid rgba(148,163,184,.15);">
                            <td style="padding:.4rem .5rem;">{{ $line->job?->job_number ?? 'deleted job' }}</td>
                            <td style="padding:.4rem .5rem;">{{ $line->part_number }}</td>
                            <td style="padding:.4rem .5rem;text-align:right;">{{ \App\Support\Money::format($line->line_total_cents) }}</td>
                            <td style="padding:.4rem .5rem;text-align:right;">{{ \App\Support\Money::format((int) $line->line_total_cents - (int) $line->variance_cents) }}</td>
                            <td style="padding:.4rem .5rem;text-align:right;color:{{ $line->variance_cents > 0 ? '#dc2626' : '#d97706' }};">
                                {{ $line->variance_cents > 0 ? '+' : '' }}{{ \App\Support\Money::format($line->variance_cents) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>
