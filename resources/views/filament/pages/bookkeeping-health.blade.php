<x-filament-panels::page>
    @php($m = $this->getMetrics())

    <div class="grid gap-6 md:grid-cols-3">
        {{-- Counter 1: transactions accounted for --}}
        <x-filament::section>
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $m['transactions']['label'] }}</p>
                    <div class="mt-1 flex items-baseline gap-2">
                        @if ($m['transactions']['open'] === 0)
                            <span class="text-3xl font-bold text-success-600 dark:text-success-400">All clear</span>
                        @else
                            <span class="text-4xl font-bold text-warning-600 dark:text-warning-400">{{ $m['transactions']['open'] }}</span>
                            <span class="text-sm text-gray-500">to review</span>
                        @endif
                    </div>
                </div>
                <x-filament::icon
                    :icon="$m['transactions']['open'] === 0 ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle'"
                    @class([
                        'h-8 w-8',
                        'text-success-500' => $m['transactions']['open'] === 0,
                        'text-warning-500' => $m['transactions']['open'] !== 0,
                    ])
                />
            </div>

            @if ($m['transactions']['total'] > 0)
                <div class="mt-4">
                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                        <div class="h-full rounded-full bg-success-500"
                             style="width: {{ (int) round($m['transactions']['done'] / max(1, $m['transactions']['total']) * 100) }}%"></div>
                    </div>
                    <p class="mt-2 text-xs text-gray-500">
                        {{ $m['transactions']['done'] }} of {{ $m['transactions']['total'] }} posted transactions matched
                    </p>
                </div>
            @endif

            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ $m['transactions']['caption'] }}</p>

            <x-slot name="footer">
                <a href="{{ $m['transactions']['url'] }}" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
                    {{ $m['transactions']['cta'] }} &rarr;
                </a>
            </x-slot>
        </x-filament::section>

        {{-- Counter 2: paid jobs reconciled --}}
        <x-filament::section>
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $m['jobs']['label'] }}</p>
                    <div class="mt-1 flex items-baseline gap-2">
                        @if ($m['jobs']['open'] === 0)
                            <span class="text-3xl font-bold text-success-600 dark:text-success-400">All reconciled</span>
                        @else
                            <span class="text-4xl font-bold text-warning-600 dark:text-warning-400">{{ $m['jobs']['open'] }}</span>
                            <span class="text-sm text-gray-500">unconfirmed</span>
                        @endif
                    </div>
                </div>
                <x-filament::icon
                    :icon="$m['jobs']['open'] === 0 ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle'"
                    @class([
                        'h-8 w-8',
                        'text-success-500' => $m['jobs']['open'] === 0,
                        'text-warning-500' => $m['jobs']['open'] !== 0,
                    ])
                />
            </div>

            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ $m['jobs']['caption'] }}</p>

            <x-slot name="footer">
                <a href="{{ $m['jobs']['url'] }}" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
                    {{ $m['jobs']['cta'] }} &rarr;
                </a>
            </x-slot>
        </x-filament::section>

        {{-- Counter 3: Mygrant parts reconciled --}}
        <x-filament::section>
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $m['mygrant']['label'] }}</p>
                    <div class="mt-1 flex items-baseline gap-2">
                        @if ($m['mygrant']['open'] === 0)
                            <span class="text-3xl font-bold text-success-600 dark:text-success-400">All clear</span>
                        @else
                            <span class="text-4xl font-bold text-warning-600 dark:text-warning-400">{{ $m['mygrant']['open'] }}</span>
                            <span class="text-sm text-gray-500">to reconcile</span>
                        @endif
                    </div>
                </div>
                <x-filament::icon
                    :icon="$m['mygrant']['open'] === 0 ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle'"
                    @class([
                        'h-8 w-8',
                        'text-success-500' => $m['mygrant']['open'] === 0,
                        'text-warning-500' => $m['mygrant']['open'] !== 0,
                    ])
                />
            </div>

            <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">{{ $m['mygrant']['caption'] }}</p>

            <x-slot name="footer">
                <a href="{{ $m['mygrant']['url'] }}" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
                    {{ $m['mygrant']['cta'] }} &rarr;
                </a>
            </x-slot>
        </x-filament::section>
    </div>

    {{-- Informational: money still owed (not a reconciliation gap) --}}
    <x-filament::section>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $m['owed']['label'] }}</p>
                <p class="mt-1 text-2xl font-bold text-gray-900 dark:text-white">
                    {{ $m['owed']['amount'] }}
                    <span class="text-sm font-normal text-gray-500">across {{ $m['owed']['open'] }} {{ \Illuminate\Support\Str::plural('job', $m['owed']['open']) }}</span>
                </p>
                <p class="mt-1 text-xs text-gray-500">Work done, payment not yet collected — money owed to us, not a books gap.</p>
            </div>
            <a href="{{ $m['owed']['url'] }}" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
                {{ $m['owed']['cta'] }} &rarr;
            </a>
        </div>
    </x-filament::section>
</x-filament-panels::page>
