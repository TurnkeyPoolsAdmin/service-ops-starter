<?php

namespace App\Services\Messaging;

use App\Models\Setting;

/**
 * Typed accessor over the settings table. The DB value wins; config('glass.*')
 * supplies the fallback default so a fresh install behaves sensibly before an
 * admin touches the Timing screen.
 */
class SettingsRepository
{
    public function reminderNightBeforeAt(): string
    {
        return Setting::get('reminder_night_before_at', config('glass.reminders.night_before_at', '18:00'));
    }

    public function reminderMorningOfAt(): string
    {
        return Setting::get('reminder_morning_of_at', config('glass.reminders.morning_of_at', '08:00'));
    }
}
