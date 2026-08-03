<x-filament-panels::page>
    {{-- Subview tabs — a wrapping button row instead of header actions, which
         overflowed the page once there were nine of them. Inline styles only:
         this panel runs Filament's prebuilt CSS with no custom theme compiled. --}}
    <div style="display:flex;flex-wrap:wrap;gap:.5rem;">
        @foreach ($this->subviewTabs() as $tab)
            <x-filament::button
                wire:click="switchSubview('{{ $tab['key'] }}')"
                :color="$tab['active'] ? 'primary' : 'gray'"
                size="sm"
            >
                {{ $tab['label'] }}
                @if ($tab['count'] > 0)
                    <span style="margin-left:.4rem;display:inline-flex;align-items:center;justify-content:center;min-width:1.15rem;height:1.15rem;padding:0 .3rem;border-radius:9999px;background:rgba(120,120,120,.28);font-size:.7rem;font-weight:700;line-height:1;">{{ $tab['count'] }}</span>
                @endif
            </x-filament::button>
        @endforeach
    </div>

    {{ $this->table }}
</x-filament-panels::page>
