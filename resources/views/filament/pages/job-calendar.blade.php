<x-filament-panels::page>
    @php
        $tz = \App\Support\Timezone::display();
        $hh = \App\Filament\Pages\JobCalendar::HOUR_HEIGHT;

        // Entity code -> [background, border, text]. SFV/AGLA share the LA hue.
        // Fallback palette for a tech with no calendar color set.
        $entityColors = fn ($code) => match ($code) {
            'LA', 'SFV' => ['#eff6ff', '#bfdbfe', '#1e3a8a'],
            'DelMar'    => ['#fffbeb', '#fcd34d', '#78350f'],
            default     => ['#f3f4f6', '#d1d5db', '#1f2937'],
        };

        // Warranty return visits get their own hue (purple) on every entity.
        $warrantyColors = ['#f5f3ff', '#c4b5fd', '#4c1d95'];

        // Darken a hex color toward black (for readable text on a light tint).
        $darken = function (string $hex, float $f) {
            $hex = ltrim($hex, '#');
            if (strlen($hex) === 3) { $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]; }
            [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
            return sprintf('#%02x%02x%02x', (int) ($r * $f), (int) ($g * $f), (int) ($b * $f));
        };

        // A tech's chosen calendar color -> [tinted background, border, text].
        $techColors = fn (string $hex) => [$hex.'22', $hex, $darken($hex, 0.5)];

        $jobUrl = fn ($job) => \App\Filament\Resources\Jobs\JobResource::getUrl('view', ['record' => $job]);

        // One display shape for both kinds of calendar item (job / warranty visit).
        // Blocks are colored by their technician (falling back to the entity hue
        // when the tech has no color set); warranty comebacks stay purple.
        $eventBits = function (string $kind, $model) use ($entityColors, $warrantyColors, $techColors, $jobUrl) {
            $isWarranty = $kind === 'warranty';
            $job = $isWarranty ? $model->job : $model;
            $tech = $model->technician;

            $colors = $isWarranty
                ? $warrantyColors
                : ($tech?->calendar_color ? $techColors($tech->calendar_color) : $entityColors($model->entity?->code));

            // First word only, to keep the blocks uncluttered.
            $first = fn (?string $full) => $full ? preg_split('/\s+/', trim($full))[0] : $full;

            return [
                'colors' => $colors,
                'url' => $job ? $jobUrl($job) : null,
                'prefix' => $isWarranty ? 'Warranty · ' : '',
                'name' => $first($job?->customer_name_snapshot) ?: 'Customer',
                'tech' => $first($model->technician?->name) ?: 'Unassigned',
                'exact' => (bool) $model->scheduled_exact_time,
                'entity' => $model->entity?->code,
                'city' => $job?->customer?->city,
            ];
        };

        $hourLabel = fn (int $h) => $h === 0 ? '12 AM' : ($h < 12 ? $h.' AM' : ($h === 12 ? '12 PM' : ($h - 12).' PM'));
    @endphp

    <style>
        .gcal-wrap { --line:#eef0f2; --txt:#111827; --muted:#6b7280; --card:#fff; }
        .dark .gcal-wrap { --line:rgba(255,255,255,.07); --txt:#e5e7eb; --muted:#9ca3af; --card:#18181b; }

        .gcal { border:1px solid var(--line); border-radius:.75rem; overflow:hidden; background:var(--card); }

        /* ---- Week / day time grid ---- */
        .gcal-head { display:grid; grid-template-columns:60px repeat(var(--cols),minmax(0,1fr)); border-bottom:1px solid var(--line); }
        .gcal-corner { font-size:.62rem; color:var(--muted); display:flex; align-items:flex-end; justify-content:center; padding:4px; }
        .gcal-dh { display:flex; flex-direction:column; align-items:center; gap:2px; padding:6px 0; background:none; border:0; border-left:1px solid var(--line); cursor:pointer; }
        .gcal-dh:hover .dnum { background:var(--line); }
        .gcal-dh .dow { font-size:.68rem; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); }
        .gcal-dh .dnum { font-size:1.05rem; font-weight:600; color:var(--txt); width:1.85rem; height:1.85rem; display:flex; align-items:center; justify-content:center; border-radius:9999px; }
        .gcal-dh.is-today .dnum { background:#2563eb; color:#fff; }

        .gcal-body { display:grid; grid-template-columns:60px repeat(var(--cols),minmax(0,1fr)); max-height:70vh; overflow-y:auto; }
        .gcal-gutter { position:relative; }
        .gcal-hr { position:relative; }
        .gcal-hr span { position:absolute; top:-7px; right:6px; font-size:.62rem; color:var(--muted); white-space:nowrap; }
        .gcal-col { position:relative; border-left:1px solid var(--line); }
        .gcal-cell { border-top:1px solid var(--line); }
        .gcal-gutter .gcal-cell { border-top-color:transparent; }
        .gcal-ev { position:absolute; border:1px solid; border-radius:6px; padding:1px 6px; font-size:11px; line-height:1.3; overflow:hidden; text-decoration:none; box-shadow:0 1px 1px rgba(0,0,0,.06); }
        .gcal-ev:hover { filter:brightness(.97); box-shadow:0 1px 4px rgba(0,0,0,.12); }
        .gcal-ev .t { font-weight:700; }

        /* ---- Month overview ---- */
        .gcal-mhead, .gcal-mgrid { display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); }
        .gcal-mhead { border-bottom:1px solid var(--line); }
        .gcal-mhead div { text-align:center; padding:6px 0; font-size:.68rem; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); }
        .gcal-mcell { min-height:112px; border-top:1px solid var(--line); border-left:1px solid var(--line); padding:4px; }
        .gcal-mcell:nth-child(6n+1) { border-left:0; }
        .gcal-mcell.is-out { background:rgba(0,0,0,.015); }
        .dark .gcal-mcell.is-out { background:rgba(255,255,255,.02); }
        .gcal-mdaynum { width:1.6rem; height:1.6rem; display:flex; align-items:center; justify-content:center; border-radius:9999px; font-size:.78rem; font-weight:600; color:var(--txt); background:none; border:0; cursor:pointer; margin-bottom:2px; }
        .gcal-mdaynum:hover { background:var(--line); }
        .gcal-mcell.is-out .gcal-mdaynum { color:var(--muted); }
        .gcal-mdaynum.is-today { background:#2563eb; color:#fff; }
        .gcal-mev { display:block; border:1px solid; border-radius:4px; padding:0 4px; margin-top:2px; font-size:11px; line-height:1.45; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; text-decoration:none; }
        .gcal-mev .t { font-weight:700; }

        /* ---- Heading row (plain CSS, not Tailwind — this panel has no compiled theme) ---- */
        .gcal-heading { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:6px; }
        .gcal-heading h2 { font-size:1.25rem; font-weight:600; color:var(--txt,#111827); margin:0; }
        .dark .gcal-heading h2 { color:#fff; }
        .gcal-heading span { font-size:.875rem; color:#6b7280; }
        .dark .gcal-heading span { color:#9ca3af; }

        /* ---- Desktop grid vs. mobile agenda: pure CSS visibility swap ---- */
        .gcal-agenda { display:none; }
        @media (max-width: 640px) {
            .gcal-desktop { display:none; }
            .gcal-agenda { display:block; }
            /* Let the header's prev/next/today/mode buttons wrap instead of overflowing/shrinking unreadably. */
            .fi-header-actions-ctn { flex-wrap:wrap; row-gap:.5rem; }
        }

        /* ---- Mobile agenda list ---- */
        .gcal-agenda-day { margin-bottom:14px; }
        .gcal-agenda-daylabel { display:flex; align-items:center; gap:6px; font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:var(--muted); padding:6px 2px; }
        .gcal-agenda-daylabel.is-today { color:#2563eb; }
        .gcal-agenda-todaytag { background:#2563eb; color:#fff; font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.02em; padding:1px 7px; border-radius:9999px; }
        .gcal-agenda-ev { display:flex; flex-direction:column; gap:2px; min-height:44px; justify-content:center; padding:8px 10px; margin-bottom:6px; border:1px solid; border-left-width:4px; border-radius:8px; text-decoration:none; }
        .gcal-agenda-time { font-size:.72rem; font-weight:700; }
        .gcal-agenda-title { font-size:.88rem; font-weight:600; }
        .gcal-agenda-sub { font-size:.75rem; opacity:.85; }
        .gcal-agenda-empty, .gcal-agenda-none { font-size:.85rem; color:var(--muted); padding:8px 2px; }
    </style>

    {{-- Filters --}}
    <x-filament::section>
        {{ $this->form }}
    </x-filament::section>

    {{-- Range heading --}}
    <div class="gcal-heading">
        <h2>{{ $this->heading() }}</h2>
        <span>{{ $tz }}</span>
    </div>

    <div class="gcal-wrap">
        <div class="gcal-desktop">
        @if ($mode === 'month')
            @php $today = $this->todayKey(); @endphp
            <div class="gcal">
                <div class="gcal-mhead">
                    @foreach (['Mon','Tue','Wed','Thu','Fri','Sat'] as $dow)
                        <div>{{ $dow }}</div>
                    @endforeach
                </div>
                <div class="gcal-mgrid">
                    @foreach ($this->monthWeeks() as $week)
                        @foreach ($week as $day)
                            @php
                                $key = $day->format('Y-m-d');
                                $isOut = $day->month !== $this->anchorMonth();
                            @endphp
                            <div class="gcal-mcell {{ $isOut ? 'is-out' : '' }}">
                                <button type="button" wire:click="openDay('{{ $key }}')"
                                        class="gcal-mdaynum {{ $key === $today ? 'is-today' : '' }}" title="Open day view">
                                    {{ $day->day }}
                                </button>
                                @foreach ($this->itemsFor($key) as $item)
                                    @php $ev = $eventBits($item['kind'], $item['model']); [$bg,$bd,$tc] = $ev['colors']; @endphp
                                    <a href="{{ $ev['url'] ?? '#' }}" class="gcal-mev"
                                       style="background:{{ $bg }};border-color:{{ $bd }};color:{{ $tc }};"
                                       title="{{ $ev['prefix'] }}{{ $ev['name'] }} — {{ $ev['entity'] }} · Tech: {{ $ev['tech'] }}{{ $ev['city'] ? ' · '.$ev['city'] : '' }}">
                                        <span class="t">{{ $item['at']->format('g:ia') }}{{ $ev['exact'] ? ' exact' : '' }}</span>
                                        {{ $ev['prefix'] }}{{ $ev['name'] }} · Tech: {{ $ev['tech'] }}{{ $ev['city'] ? ' · '.$ev['city'] : '' }}
                                    </a>
                                @endforeach
                            </div>
                        @endforeach
                    @endforeach
                </div>
            </div>
        @else
            @php
                [$startHour, $endHour] = $this->hourRange();
                $days = $this->gridDays();
            @endphp
            <div class="gcal" style="--cols: {{ count($days) }};">
                <div class="gcal-head">
                    <div class="gcal-corner">{{ \App\Support\Timezone::now()->format('T') }}</div>
                    @foreach ($days as $d)
                        <button type="button" wire:click="openDay('{{ $d['key'] }}')"
                                class="gcal-dh {{ $d['isToday'] ? 'is-today' : '' }}" title="Open day view">
                            <span class="dow">{{ $d['date']->format('D') }}</span>
                            <span class="dnum">{{ $d['date']->format('j') }}</span>
                        </button>
                    @endforeach
                </div>
                <div class="gcal-body">
                    {{-- Hour labels --}}
                    <div class="gcal-gutter">
                        @for ($h = $startHour; $h < $endHour; $h++)
                            <div class="gcal-hr gcal-cell" style="height:{{ $hh }}px;"><span>{{ $hourLabel($h) }}</span></div>
                        @endfor
                    </div>
                    {{-- Day columns --}}
                    @foreach ($days as $d)
                        <div class="gcal-col">
                            @for ($h = $startHour; $h < $endHour; $h++)
                                <div class="gcal-cell" style="height:{{ $hh }}px;"></div>
                            @endfor
                            @foreach ($this->dayEvents($d['key']) as $event)
                                @php $ev = $eventBits($event['kind'], $event['model']); [$bg,$bd,$tc] = $ev['colors']; @endphp
                                @php $window = $event['time'].'–'.$event['endTime']; @endphp
                                <a href="{{ $ev['url'] ?? '#' }}" class="gcal-ev"
                                   style="top:{{ $event['top'] }}px; height:{{ $event['height'] }}px; left:calc({{ $event['leftPct'] }}% + 2px); width:calc({{ $event['widthPct'] }}% - 4px); background:{{ $bg }}; border-color:{{ $bd }}; color:{{ $tc }};"
                                   title="{{ $ev['exact'] ? $event['time'].' exact' : $window }} · {{ $ev['prefix'] }}{{ $ev['name'] }} — {{ $ev['entity'] }} · Tech: {{ $ev['tech'] }}{{ $ev['city'] ? ' · '.$ev['city'] : '' }}">
                                    @if ($ev['exact'])<span class="t">{{ $event['time'] }} exact</span> @endif{{ $ev['prefix'] }}{{ $ev['name'] }}<br>
                                    Tech: {{ $ev['tech'] }}{{ $ev['city'] ? ' · '.$ev['city'] : '' }}
                                </a>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
        </div>

        {{-- Mobile agenda — same underlying items as the grid above, just laid out as a tappable list. --}}
        <div class="gcal-agenda">
            @php $agendaDays = $this->agendaDays(); @endphp
            @if (empty($agendaDays))
                <div class="gcal-agenda-empty">No jobs scheduled this {{ $mode }}.</div>
            @else
                @foreach ($agendaDays as $day)
                    <div class="gcal-agenda-day">
                        <div class="gcal-agenda-daylabel {{ $day['isToday'] ? 'is-today' : '' }}">
                            {{ $day['date']->format('D, M j') }}
                            @if ($day['isToday'])<span class="gcal-agenda-todaytag">Today</span>@endif
                        </div>
                        @forelse ($day['items'] as $item)
                            @php
                                $ev = $eventBits($item['kind'], $item['model']);
                                [$bg, $bd, $tc] = $ev['colors'];
                                $endAt = $item['at']->copy()->addMinutes($item['minutes']);
                                $timeLabel = $ev['exact'] ? $item['at']->format('g:ia').' exact' : $item['at']->format('g:ia').'–'.$endAt->format('g:ia');
                            @endphp
                            <a href="{{ $ev['url'] ?? '#' }}" class="gcal-agenda-ev"
                               style="background:{{ $bg }}; border-color:{{ $bd }}; border-left-color:{{ $bd }}; color:{{ $tc }};">
                                <span class="gcal-agenda-time">{{ $timeLabel }}</span>
                                <span class="gcal-agenda-title">{{ $ev['prefix'] }}{{ $ev['name'] }}</span>
                                <span class="gcal-agenda-sub">Tech: {{ $ev['tech'] }}{{ $ev['city'] ? ' · '.$ev['city'] : '' }} · {{ $ev['entity'] }}</span>
                            </a>
                        @empty
                            <div class="gcal-agenda-none">No jobs scheduled.</div>
                        @endforelse
                    </div>
                @endforeach
            @endif
        </div>
    </div>
</x-filament-panels::page>
