{{--
    The AI scheduling session transcript. Styling is INLINE (plus Filament's own
    blade components) on purpose, same as the job notes feed — this panel ships
    Filament's prebuilt CSS with no custom theme, so Tailwind utility classes
    written here are never compiled and render unstyled.
--}}
@php
    $bubbles = \App\Filament\Support\AiSchedulingTranscript::bubbles($session);
    $job = $session->job;
    $constraint = $session->constraintObject();
    $isLive = in_array($session->status, ['running', 'paused'], true);
@endphp

<x-filament::section>
    <x-slot name="heading">
        Session #{{ $session->id }} &mdash; {{ $job?->job_number ?? 'job deleted' }}
    </x-slot>
    <x-slot name="description">
        {{ $job?->customer?->full_name ?? '—' }} &middot; targeting {{ $constraint->phrase(\App\Enums\Language::English) }}
    </x-slot>

    <div style="display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;margin-bottom:1rem;">
        <x-filament::badge :color="match (true) {
            $session->status === 'running' => 'info',
            $session->status === 'paused' => 'warning',
            in_array($session->status, ['scheduled', 'simulated']) => 'success',
            in_array($session->status, ['exhausted', 'canceled']) => 'danger',
            default => 'gray',
        }">
            {{ ucfirst($session->status) }}
        </x-filament::badge>
        <x-filament::badge color="gray">Tech: {{ ucfirst($session->tech_mode) }}</x-filament::badge>
        <x-filament::badge color="gray">Customer: {{ ucfirst($session->customer_mode) }}</x-filament::badge>

        @if ($session->paused_reason)
            <span style="font-size:.8rem;color:#94a3b8;">&mdash; {{ $session->paused_reason }}</span>
        @endif
    </div>

    @if ($isLive)
        <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1.25rem;">
            @if ($session->status === 'running')
                <x-filament::button size="sm" color="warning" wire:click="mountAction('pauseSession')">
                    Pause
                </x-filament::button>
                @if ($session->currentAttempt)
                    <x-filament::button size="sm" color="gray" wire:click="mountAction('skipTech')">
                        Skip this tech
                    </x-filament::button>
                @endif
            @else
                <x-filament::button size="sm" color="success" wire:click="mountAction('resumeSession')">
                    Resume
                </x-filament::button>
            @endif

            <x-filament::button size="sm" color="gray" wire:click="mountAction('takeOver')">
                Take over
            </x-filament::button>
            <x-filament::button size="sm" color="danger" wire:click="mountAction('cancelSession')">
                Cancel
            </x-filament::button>

            @if ($session->tech_mode === 'simulate')
                <x-filament::button size="sm" color="primary" wire:click="mountAction('simulateReply')">
                    Simulate a reply
                </x-filament::button>
            @endif
        </div>
    @endif

    @if (empty($bubbles))
        <p style="font-size:.875rem;color:#94a3b8;">Nothing yet.</p>
    @else
        <ul style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.6rem;">
            @foreach ($bubbles as $bubble)
                @php
                    $align = match ($bubble['party']) {
                        'tech' => 'flex-start',
                        'customer' => 'flex-end',
                        default => 'center',
                    };
                    $bg = match (true) {
                        $bubble['party'] === 'system' => 'rgba(148,163,184,.10)',
                        $bubble['is_pending'] => 'rgba(245,158,11,.14)',
                        $bubble['direction'] === 'in' => 'rgba(99,102,241,.14)',
                        default => 'rgba(34,197,94,.12)',
                    };
                    $border = $bubble['is_pending']
                        ? '1px dashed rgba(245,158,11,.6)'
                        : '1px solid rgba(148,163,184,.25)';
                @endphp
                <li style="display:flex;justify-content:{{ $align }};">
                    <div style="max-width:32rem;min-width:0;border-radius:.75rem;padding:.5rem .75rem;background:{{ $bg }};border:{{ $border }};">
                        @if ($bubble['party'] !== 'system')
                            <p style="font-size:.75rem;color:#94a3b8;margin:0 0 .15rem;">
                                <span style="font-weight:600;color:#64748b;">{{ $bubble['who'] }}</span>
                                <span>{{ $bubble['direction'] === 'out' ? '→' : '←' }}</span>
                                @if ($bubble['at'] !== '')
                                    &middot; {{ $bubble['at'] }}
                                @endif
                                @if ($bubble['is_pending'])
                                    <span style="font-style:italic;color:#b45309;">&middot; awaiting approval</span>
                                @endif
                            </p>
                        @endif

                        <p style="margin:0;font-size:.875rem;white-space:pre-wrap;overflow-wrap:anywhere;{{ $bubble['party'] === 'system' ? 'font-style:italic;color:#94a3b8;text-align:center;' : '' }}">
                            {{ $bubble['body'] }}
                        </p>

                        @if ($bubble['intent'] !== null && $bubble['direction'] === 'in')
                            <p style="margin:.25rem 0 0;font-size:.7rem;color:#94a3b8;">
                                {{ $bubble['intent'] }}
                                @if ($bubble['confidence'] !== null)
                                    ({{ number_format($bubble['confidence'] * 100) }}%)
                                @endif
                                &middot; {{ $bubble['used_llm'] ? 'AI' : 'keyword match' }}
                            </p>
                        @endif

                        @if ($bubble['is_pending'])
                            <div style="display:flex;gap:.4rem;margin-top:.4rem;">
                                <x-filament::icon-button
                                    icon="heroicon-m-paper-airplane"
                                    color="success"
                                    size="sm"
                                    label="Send"
                                    tooltip="Send this text"
                                    wire:click="mountAction('approveTurn', { turn: {{ $bubble['id'] }} })"
                                />
                                <x-filament::icon-button
                                    icon="heroicon-m-x-mark"
                                    color="gray"
                                    size="sm"
                                    label="Don't send"
                                    tooltip="Don't send this text"
                                    wire:click="mountAction('skipTurn', { turn: {{ $bubble['id'] }} })"
                                />
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-filament::section>
