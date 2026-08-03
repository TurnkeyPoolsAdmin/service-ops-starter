<?php

namespace App\Support;

use App\Models\Holiday;
use Carbon\CarbonInterface;

/**
 * "Are we outside normal scheduling hours right now?" — the window where the
 * office would otherwise have to phone techs to find coverage. Mirrors the
 * thresholds that drive the after-hours/weekend/holiday customer welcome
 * variants (see TemplateKey::welcomeDispatchIntroFor):
 *
 *   - any time on a holiday
 *   - all day Sunday, or Saturday from 4pm
 *   - Mon–Fri from 7pm
 *
 * Used to gate the tech-availability outreach prompt so it only appears when
 * it's actually useful.
 */
class BusinessHours
{
    public static function isAfterHours(CarbonInterface $localNow): bool
    {
        if (Holiday::isHoliday($localNow)) {
            return true;
        }

        if ($localNow->isSunday()) {
            return true;
        }
        if ($localNow->isSaturday()) {
            return $localNow->hour >= 16;
        }

        return $localNow->hour >= 19;
    }
}
