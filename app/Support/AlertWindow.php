<?php

namespace App\Support;

/**
 * The single rule for WHEN a recurring internal alert may post to the Telegram
 * group chat: local (Pacific) wall-clock hours only, 7am-7pm by default, and
 * never on a silent day (Sunday — we are closed, see App\Support\ClosedDays).
 *
 * Every recurring sweep runs on a bare interval (every 5 / 15 / 30 minutes, or
 * hourly), and an interval schedule has no idea what time of day it is — so
 * without this gate a nag posts all night. That is exactly what happened to the
 * same-day parts nag: at 00:00 Pacific the calendar day rolls over, every job
 * scheduled for the coming day whose part is not ordered becomes "TODAY", and
 * the group got a 🚨 post every 30 minutes from midnight until someone woke up.
 *
 * ⚠️ Only gate an alert that REPOSTS. A suppressed run must be able to say the
 * same thing again after 7am — either because the builder rebuilds a live list
 * from the database each run (the parts / review-call nags), or because the
 * "already told them" stamp is only written on a successful send (the overdue
 * alerts, the delivery watchdog, the connector health transitions). A one-shot
 * event alert (a booking, a failed send, an escalation) must NOT be gated here:
 * there is no second run to carry it, so gating it would drop it for good.
 *
 * ⚠️ The SILENT DAY rule is different, and deliberately so. It is a whole day
 * off, so a one-shot alert on a Sunday IS lost — there is no later run that
 * day to carry it. That is the owner's call (2026-08-03): the shop is closed
 * Sunday, nobody acts on an alert, so the group chat stays quiet. The rule is
 * therefore ALSO enforced centrally in TelegramNotifier::send(), which covers
 * the one-shot alerts that never come near this class.
 */
class AlertWindow
{
    /**
     * True when an alert may post right now: an open day, inside the hours.
     */
    public static function open(): bool
    {
        if (self::silentToday()) {
            return false;
        }

        $now = Timezone::now()->format('H:i');

        return $now >= self::start() && $now <= self::end();
    }

    /** True when today (local) is a day we post no Telegram alerts at all. */
    public static function silentToday(): bool
    {
        $today = strtolower(Timezone::now()->format('l'));

        foreach (self::silentDays() as $day) {
            // Accept both "sunday" and the "sun" shorthand; anything shorter is
            // a typo we must not act on ("s" would silence Saturday too).
            if ($day === $today || (strlen($day) >= 3 && str_starts_with($today, $day))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The configured silent days, lowercased. Accepts an array or a
     * comma-separated string (so the env var can set it); empty = never silent.
     *
     * @return array<int, string>
     */
    public static function silentDays(): array
    {
        $configured = config('glass.telegram.silent_days', ['sunday']);

        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        return array_values(array_filter(array_map(
            fn ($day) => strtolower(trim((string) $day)),
            (array) $configured,
        )));
    }

    public static function start(): string
    {
        return (string) config('glass.telegram.alert_window_start', '07:00');
    }

    public static function end(): string
    {
        return (string) config('glass.telegram.alert_window_end', '19:00');
    }
}
