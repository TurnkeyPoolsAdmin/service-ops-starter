<?php

namespace App\Support;

use App\Enums\Language;
use App\Enums\TemplateKey;
use App\Models\Job;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Picks the post-welcome "scheduling ask" text for a fresh lead and builds its
 * merge vars. Sent by hand from the dispatcher's Send-welcome screen / the job
 * actions menu (JobActions::sendSchedulingAsk) — never automatically.
 *
 * Two axes decide the copy:
 *
 *  1. WHEN they asked for, relative to the next business day (NBD = tomorrow,
 *     or the next day that isn't a Sunday/holiday — see BusinessDays):
 *       - no usable date        -> propose the NBD
 *       - on/before the NBD     -> just confirm the day
 *       - after the NBD         -> confirm, AND offer to pull them earlier
 *  2. WHETHER they gave a time preference (TimePreference::isKnown()):
 *       - no  -> ASK for one, or offer our earliest
 *       - yes -> ACKNOWLEDGE it and promise the window
 *
 * A job requested for TODAY (or a past day) returns null — that's a phone call,
 * not a text, so no scheduling ask exists for it.
 *
 * The pull-earlier nudge is the point of the "future" variants: the further out
 * an appointment sits, the more of them cancel. {earlier_window} names one or
 * two concrete near days ("tomorrow (Tuesday)", "Tuesday or Wednesday") so
 * saying yes is cheap, instead of vaguely pushing back on their date.
 */
final class SchedulingAsk
{
    private function __construct(
        public readonly TemplateKey $template,
        /** @var array<string, string> extra merge vars, layered over the job's own */
        public readonly array $vars,
    ) {}

    /**
     * The scheduling ask for a job, or null when there is nothing to send
     * (requested today/in the past — dispatch calls those).
     */
    public static function for(Job $job, ?CarbonInterface $nowLocal = null): ?self
    {
        $now = CarbonImmutable::parse(($nowLocal ?? Timezone::now())->format('Y-m-d H:i:s'), Timezone::display());
        $today = $now->format('Y-m-d');

        $date = $job->requestedDate();

        // Requested today or already past: we call, we don't text.
        if ($date !== null && $date <= $today) {
            return null;
        }

        $language = $job->customer?->preferredLanguage();
        $preference = $job->timePreference();
        $known = $preference->isKnown();

        $nbd = BusinessDays::nextBusinessDay($now);

        $vars = [
            'requested_day' => RequestedDay::spellOut($date, $language) ?? '',
            'requested_time' => $preference->phrase($job->requested_time, $language, $job->requestedAfterTime()),
            'earliest_day' => self::dayPhrase($nbd, $now, $language),
        ];

        if ($date === null) {
            return new self(TemplateKey::SchedulingAskNoDate, $vars);
        }

        // On (or, for a legacy row landing on a closed day, before) the next
        // business day: nothing to pull forward, just settle the time.
        if ($date <= $nbd->format('Y-m-d')) {
            return new self(
                $known ? TemplateKey::SchedulingConfirmNextDay : TemplateKey::SchedulingAskNextDay,
                $vars,
            );
        }

        $vars['earlier_window'] = self::earlierWindow($nbd, $date, $now, $language);

        return new self(
            $known ? TemplateKey::SchedulingConfirmFuture : TemplateKey::SchedulingAskFuture,
            $vars,
        );
    }

    /**
     * A near-future day the way a person would say it, so the copy never reads
     * like a database field: "tomorrow (Tuesday)" when it literally is
     * tomorrow, the bare weekday within the coming week, and the full date
     * beyond that.
     */
    private static function dayPhrase(CarbonImmutable $day, CarbonImmutable $now, ?Language $language): string
    {
        $spanish = $language === Language::Spanish;
        $daysOut = $now->startOfDay()->diffInDays($day->startOfDay());

        if ($daysOut <= 1) {
            $weekday = self::weekday($day, $language);

            return $spanish ? "mañana ({$weekday})" : "tomorrow ({$weekday})";
        }

        if ($daysOut <= 6) {
            return self::weekdayWithArticle($day, $language);
        }

        return RequestedDay::spellOut($day->format('Y-m-d'), $language) ?? '';
    }

    /**
     * The concrete earlier option(s) offered to someone booked past the next
     * business day. Two days get named when at least two business days sit
     * before their date ("Tuesday or Wednesday") — reads as flexibility rather
     * than a single take-it-or-leave-it slot; otherwise just the NBD.
     */
    private static function earlierWindow(
        CarbonImmutable $nbd,
        string $requestedDate,
        CarbonImmutable $now,
        ?Language $language,
    ): string {
        $days = [];
        $cursor = $nbd;
        while (count($days) < 2 && $cursor->format('Y-m-d') < $requestedDate) {
            $days[] = $cursor;
            $cursor = BusinessDays::nextBusinessDay($cursor);
        }

        if (count($days) < 2) {
            return self::dayPhrase($nbd, $now, $language);
        }

        $or = $language === Language::Spanish ? ' o ' : ' or ';

        return self::dayPhrase($days[0], $now, $language)
            .$or
            .self::weekdayWithArticle($days[1], $language);
    }

    /** "Tuesday" / "el martes". */
    private static function weekdayWithArticle(CarbonImmutable $day, ?Language $language): string
    {
        $weekday = self::weekday($day, $language);

        return $language === Language::Spanish ? "el {$weekday}" : $weekday;
    }

    /** "Tuesday" / "martes". */
    private static function weekday(CarbonImmutable $day, ?Language $language): string
    {
        return $language === Language::Spanish
            ? $day->locale('es')->translatedFormat('l')
            : $day->format('l');
    }
}
