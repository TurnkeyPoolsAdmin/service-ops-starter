{{--
    Styling note (see CLAUDE.md): this panel ships Filament's prebuilt CSS with
    no custom theme, so bare Tailwind utility classes in OUR OWN views never
    compile. The stat-card-style status banner reuses the exact classes other
    finance pages already use inside x-filament::section (proven compiled,
    e.g. BookkeepingHealth); the bespoke lists (documents / variances / IGC
    tables) use INLINE styles + x-filament::* components instead, same as
    JobTimeline / the job notes feed.

    Supplier tab strip (Mygrant / IGC) — same "page state a closure query
    reads at request time" pattern as Issues::subviewTabs / switchSubview.

    Header-action modals (2026-07-30, root-caused): Filament renders the
    single shared action-modals partial (all header + table row actions,
    gated by $this->hasActionsModalRendered so only the FIRST copy Blade
    evaluates actually outputs anything) from several possible spots — the
    page-chrome layout, topbar, sidebar, AND the table component's own root
    div (vendor/filament/tables/.../index.blade.php). Our page renders
    {{ $this->table }} inside its own body slot, which Blade evaluates
    BEFORE the page-chrome's copy further down the layout — so the TABLE's
    internal copy wins the race and the modal HTML ends up physically
    nested inside the table's own DOM subtree, not at page-chrome level.
    That's harmless normally, but it means ANY wrapper we put around the
    table to hide it on the IGC tab also wraps the modal — and a
    display:none wrapper hides it entirely, while an overflow/clip-based
    "visually hidden" wrapper still hides it too: a position:fixed
    descendant escapes an ancestor's LAYOUT/offset but not its paint-time
    overflow/clip region in Chromium (confirmed via live computed-style +
    screenshot diffing — the modal had correct opacity/visibility/geometry
    but still failed to actually paint).

    Fix: force the shared partial to render HERE, first, unwrapped, before
    the table ever gets a chance to emit its own copy — so it's guaranteed
    to live outside whatever hiding wrapper the table sits in below. This
    also means the table no longer needs to always render (the earlier
    2026-07-30 workaround, kept as an @if again below) purely to keep the
    modals anchor present for tab-switching continuity — this explicit
    call is unconditional across every request/tab, so that continuity is
    now satisfied at the source instead.
--}}
<x-filament-panels::page>
    <x-filament-actions::modals />

    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        @foreach ($this->supplierTabs() as $tab)
            <x-filament::button
                :color="$tab['active'] ? 'primary' : 'gray'"
                :outlined="! $tab['active']"
                size="sm"
                wire:click="switchSupplier('{{ $tab['key'] }}')"
            >
                {{ $tab['label'] }}
            </x-filament::button>
        @endforeach
    </div>

    @if ($this->supplier === 'igc')
        @include('filament.pages.parts-invoices-igc')
    @else
        @include('filament.pages.parts-invoices-mygrant')
    @endif

    @if ($this->supplier === 'mygrant')
        <x-filament::section heading="Lines to match">
            {{ $this->table }}
        </x-filament::section>
    @endif
</x-filament-panels::page>
