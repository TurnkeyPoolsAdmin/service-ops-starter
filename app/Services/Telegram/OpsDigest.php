<?php

namespace App\Services\Telegram;

use App\Support\Timezone;

/**
 * Composes the three daily ops digests posted to the Telegram group chat —
 * 7:00am, 12:00pm and 5:00pm PT (alerts:telegram-digest --when=morning|midday|
 * evening). Replaced the separate alerts:telegram-daily / -prepay-parts /
 * -unpaid-jobs posts on 2026-07-30: the group was getting five or six unrelated
 * texts a day at overlapping times, so everything that is "here is what still
 * needs a human" now arrives on a fixed three-times-a-day rhythm.
 *
 * MORNING posts up to TWO messages — the URGENT needs-scheduling-today list on
 * its own, then the digest. MIDDAY and EVENING post ONE message with the urgent
 * jobs merged into the needs-scheduling section and flagged 🚨: a same-day job
 * still unscheduled at noon is more urgent than it was at 7am, so it belongs in
 * front of the reader, not in a second text they have learned to skim.
 *
 * Sections, in order: needs scheduling → on hold (waiting on the customer) →
 * parts ready to order → prepay these parts (today + tomorrow) → completed but
 * unpaid. Each builder returns null
 * when its queue is empty and the section is dropped, so a quiet afternoon posts
 * a short digest instead of four empty headings. buildMessages() returns [] when
 * there is nothing at all to say and the caller stays silent.
 */
class OpsDigest
{
    /** Slot => the heading it posts under. */
    public const SLOTS = [
        'morning' => 'Morning digest',
        'midday' => 'Midday digest',
        'evening' => 'Evening digest',
    ];

    public function __construct(
        private readonly DailyOpsAlert $queues,
        private readonly SchedulingHoldAlert $onHold,
        private readonly PrepayPartsAlert $prepay,
        private readonly UnpaidJobsAlert $unpaid,
    ) {}

    /**
     * @param  string  $slot  one of the SLOTS keys
     * @return array<int, string> the messages to post, in order (may be empty)
     */
    public function buildMessages(string $slot): array
    {
        $morning = $slot === 'morning';
        $today = Timezone::now()->format('D, M j');

        $urgent = $this->queues->urgentJobs();
        $later = $this->queues->laterJobs();

        $messages = [];

        if ($morning && $urgent->isNotEmpty()) {
            $messages[] = $this->queues->urgentMessage($urgent);
        }

        // Morning already posted the urgent half on its own; the other two slots
        // fold it back in so nothing drops out of view after 7am.
        $scheduling = $morning ? $later : $urgent->concat($later);

        $sections = array_values(array_filter([
            $this->queues->needsSchedulingSection($scheduling, 'Needs scheduling', flagUrgent: ! $morning),
            // Directly under it: the unscheduled jobs we are NOT chasing today
            // because we are waiting on the customer. They are excluded from the
            // section above, so this is the only thing keeping them visible.
            $this->onHold->section(),
            $this->queues->partsSection($this->queues->partsToOrder()),
            $this->prepay->section(),
            $this->unpaid->section(),
        ]));

        if ($sections !== []) {
            $heading = self::SLOTS[$slot] ?? self::SLOTS['morning'];

            $messages[] = trim(implode("\n\n", array_merge(
                ["🔔 <b>Service Ops — {$heading}</b> ({$today})"],
                $sections,
            )));
        }

        return $messages;
    }
}
