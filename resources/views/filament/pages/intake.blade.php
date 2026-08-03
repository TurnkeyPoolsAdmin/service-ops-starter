<x-filament-panels::page>
    <form wire:submit="create">
        {{ $this->form }}

        @if (! empty($warnings))
            <div style="margin-top:1rem;border:1px solid #f59e0b55;background:#f59e0b14;border-radius:.5rem;padding:.75rem 1rem;">
                <div style="font-weight:600;color:#b45309;">Parse warnings ({{ count($warnings) }})</div>
                <ul style="margin:.35rem 0 0;padding-left:1.1rem;font-size:.85rem;color:#92400e;">
                    @foreach ($warnings as $w)
                        <li>{{ $w }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div style="margin-top:1.25rem;display:flex;gap:.75rem;">
            <x-filament::button type="submit">
                Confirm &amp; create job
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
