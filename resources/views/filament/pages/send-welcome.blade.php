<x-filament-panels::page>
    @php
        $tz = config('glass.timezone');
        $thread = $this->podiumThread();
        $intro = $this->previewDispatchIntro();
        $handoff = $this->previewPodiumHandoff();
    @endphp

    @if ($this->welcomeAlreadyQueued())
        <div style="border:1px solid #10b98155;background:#10b98114;border-radius:.5rem;padding:.75rem 1rem;">
            <span style="font-weight:600;color:#047857;">A welcome has already been queued for this job.</span>
            <span style="font-size:.85rem;color:#065f46;"> Use “Go to job” above.</span>
        </div>
    @endif

    @if ($this->welcomeAlreadyQueued() && $this->canTextTechs())
        <div style="border:1px solid #6366f155;background:#6366f114;border-radius:.5rem;padding:.75rem 1rem;">
            <span style="font-weight:600;color:#4338ca;">Want to check tech availability?</span>
            <span style="font-size:.85rem;color:#3730a3;"> Use “Text techs for availability” above to send the Spanish request to this location’s techs. You’ll still call to confirm.</span>
        </div>
    @endif

    @if ($this->textsPaused())
        <div style="border:1px solid #f59e0b55;background:#f59e0b14;border-radius:.5rem;padding:.75rem 1rem;">
            <span style="font-weight:600;color:#b45309;">Outgoing texts are paused (build mode).</span>
            <span style="font-size:.85rem;color:#92400e;"> Your choice is recorded and logged, but nothing is actually delivered until texting is un-paused in Admin → Integrations.</span>
        </div>
    @endif

    {{-- Who this welcome is for --}}
    <div style="border:1px solid var(--gray-200, #e5e7eb);border-radius:.5rem;padding:.75rem 1rem;">
        <div style="font-weight:600;">{{ $this->jobRecord->customer?->full_name ?: 'Customer' }}
            <span style="font-weight:400;color:#6b7280;">· {{ $this->jobRecord->customer?->phone_e164 }}</span>
        </div>
        <div style="font-size:.85rem;color:#6b7280;">
            {{ $this->jobRecord->entity?->name }} · Job {{ $this->jobRecord->job_number }}
        </div>
    </div>

    {{-- Last Podium conversation, so the dispatcher can judge whether sales handed off --}}
    <div style="border:1px solid var(--gray-200, #e5e7eb);border-radius:.5rem;padding:.75rem 1rem;">
        <div style="font-weight:600;margin-bottom:.5rem;">Last Podium conversation</div>
        @forelse ($thread as $m)
            @php $who = ($m['direction'] ?? 'inbound') === 'outbound' ? 'Sales' : 'Customer'; @endphp
            <div style="margin-bottom:.4rem;">
                <span style="font-weight:600;">{{ $who }}</span>
                <span style="font-size:.75rem;color:#9ca3af;">
                    {{ $m['at'] ? \Illuminate\Support\Carbon::parse($m['at'])->timezone($tz)->format('M j, g:ia') : '' }}
                </span><br>
                <span style="font-size:.85rem;">{{ $m['body'] }}</span>
            </div>
        @empty
            <span style="font-size:.85rem;color:#6b7280;">No Podium conversation found for this number.</span>
        @endforelse
    </div>

    {{-- Previews of exactly what each choice will send --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;">
        <div style="border:1px solid #3b82f655;background:#3b82f60d;border-radius:.5rem;padding:.75rem 1rem;">
            <div style="font-weight:600;color:#1d4ed8;">Dispatch intro — from Quo</div>
            <div style="font-size:.75rem;color:#6b7280;margin-bottom:.4rem;">Sent in every send option.</div>
            <div style="font-size:.85rem;white-space:pre-wrap;">{{ $intro ?: '⚠ Template missing/inactive — check Message Templates.' }}</div>
        </div>
        <div style="border:1px solid #f59e0b55;background:#f59e0b0d;border-radius:.5rem;padding:.75rem 1rem;">
            <div style="font-weight:600;color:#b45309;">Sales→dispatch handoff — from Podium</div>
            <div style="font-size:.75rem;color:#6b7280;margin-bottom:.4rem;">Only when sales has NOT handed off; sent first.</div>
            <div style="font-size:.85rem;white-space:pre-wrap;">{{ $handoff ?: '⚠ Template missing/inactive — check Message Templates.' }}</div>
        </div>
    </div>

    {{-- The whole lead at a glance, so nobody has to open the job to read it.
         Shows for every job — a sales-dashboard lead was never typed here. --}}
    <div style="border:1px solid var(--gray-200, #e5e7eb);border-radius:.5rem;padding:.75rem 1rem;">
        <div style="font-weight:600;margin-bottom:.5rem;">Job details</div>
        <dl style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.6rem 1.25rem;margin:0;">
            @foreach ($this->jobDetails() as $label => $value)
                <div>
                    <dt style="font-size:.7rem;text-transform:uppercase;letter-spacing:.04em;color:#9ca3af;">{{ $label }}</dt>
                    <dd style="margin:0;font-size:.875rem;white-space:pre-wrap;color:{{ filled($value) ? 'inherit' : '#9ca3af' }};">{{ filled($value) ? $value : '—' }}</dd>
                </div>
            @endforeach
        </dl>
    </div>
</x-filament-panels::page>
