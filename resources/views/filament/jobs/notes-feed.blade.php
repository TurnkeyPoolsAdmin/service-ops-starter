{{--
    The job's notes feed. Styling is INLINE (plus Filament's own blade components)
    on purpose: this panel ships Filament's prebuilt CSS with no custom theme, so
    Tailwind utility classes written here are never compiled and render unstyled.
    Colors are translucent / mid-slate so they read on both light and dark.
--}}
@php
    $bubbles = \App\Filament\Resources\Jobs\Support\JobNotesFeed::bubbles($getRecord());
@endphp

@if (empty($bubbles))
    <p style="font-size:.875rem;color:#94a3b8;">No notes yet.</p>
@else
    <ul style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.75rem;">
        @foreach ($bubbles as $bubble)
            @php
                $avatar = match (true) {
                    $bubble['is_intake'] => 'background:rgba(245,158,11,.18);color:#b45309;',
                    $bubble['is_deleted'] => 'background:rgba(148,163,184,.18);color:#94a3b8;',
                    default => 'background:rgba(99,102,241,.16);color:#6366f1;',
                };
            @endphp

            <li style="display:flex;gap:.625rem;align-items:flex-start;">
                <span style="flex-shrink:0;width:2rem;height:2rem;border-radius:9999px;font-size:.7rem;
                             font-weight:700;display:flex;align-items:center;justify-content:center;{{ $avatar }}">
                    {{ $bubble['initials'] }}
                </span>

                <div style="flex:1;min-width:0;border-radius:.75rem;padding:.5rem .75rem;
                            border:1px {{ $bubble['is_deleted'] ? 'dashed' : 'solid' }} rgba(148,163,184,.3);
                            background:rgba(148,163,184,.06);">
                    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:.5rem;">
                        <p style="font-size:.75rem;color:#94a3b8;margin:0;">
                            <span style="font-weight:600;color:#64748b;">{{ $bubble['author'] }}</span>
                            @if ($bubble['at'] !== '')
                                &middot; {{ $bubble['at'] }}
                            @endif
                            @if ($bubble['edited_at'])
                                <span style="font-style:italic;">(edited {{ $bubble['edited_at'] }})</span>
                            @endif
                        </p>

                        {{-- Author-only controls; mount the action methods on the ViewJob page. --}}
                        @if ($bubble['can_manage'])
                            <span style="display:flex;flex-shrink:0;gap:.125rem;">
                                <x-filament::icon-button
                                    icon="heroicon-m-pencil-square"
                                    color="gray"
                                    size="sm"
                                    label="Edit note"
                                    tooltip="Edit note"
                                    wire:click="mountAction('editJobNote', { note: {{ $bubble['id'] }} })"
                                />
                                <x-filament::icon-button
                                    icon="heroicon-m-trash"
                                    color="gray"
                                    size="sm"
                                    label="Delete note"
                                    tooltip="Delete note"
                                    wire:click="mountAction('deleteJobNote', { note: {{ $bubble['id'] }} })"
                                />
                            </span>
                        @endif
                    </div>

                    @if ($bubble['is_deleted'])
                        <p style="margin:.15rem 0 0;font-size:.875rem;font-style:italic;color:#94a3b8;">
                            Note deleted.
                        </p>
                    @else
                        <p style="margin:.15rem 0 0;font-size:.875rem;white-space:pre-wrap;overflow-wrap:anywhere;">
                            {{ $bubble['body'] }}
                        </p>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
@endif
