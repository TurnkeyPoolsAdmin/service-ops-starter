<x-filament-panels::page>
    {{-- Inline styles, not Tailwind utilities: this panel uses Filament's
         prebuilt CSS with no custom theme registered, so utility classes
         written in our own views are never compiled. See CLAUDE.md. --}}
    <div style="display:grid; gap:1.5rem;">
        @foreach ($this->getDataSets() as $set)
            <x-filament::section>
                <x-slot name="heading">{{ $set['title'] }}</x-slot>

                <x-slot name="description">{{ $set['description'] }}</x-slot>

                <div style="display:flex; align-items:baseline; gap:0.5rem; margin-bottom:0.75rem;">
                    <span style="font-size:1.875rem; font-weight:700;">{{ number_format($set['rows']) }}</span>
                    <span style="font-size:0.875rem; opacity:0.65;">
                        {{ \Illuminate\Support\Str::plural('row', $set['rows']) }},
                        {{ count($set['columns']) }} columns
                    </span>
                </div>

                <div style="display:flex; flex-wrap:wrap; gap:0.375rem;">
                    @foreach ($set['columns'] as $column)
                        <span style="font-size:0.75rem; padding:0.125rem 0.5rem; border-radius:9999px;
                                     background:rgba(100,116,139,0.15); white-space:nowrap;">
                            {{ $column }}
                        </span>
                    @endforeach
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
