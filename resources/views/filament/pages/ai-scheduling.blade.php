<x-filament-panels::page>
    {{ $this->table }}

    @if ($this->selectedSession())
        <div style="margin-top:1.5rem;">
            @include('filament.pages.ai-scheduling-transcript', ['session' => $this->selectedSession()])
        </div>
    @endif
</x-filament-panels::page>
