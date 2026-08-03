<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        // Non-sensitive operational defaults (only created if absent).
        $defaults = [
            'sending_phone_e164' => '+18185550000',   // single global sending number (placeholder)
            'reminder_night_before_at' => '19:30',
            'reminder_morning_of_at' => '08:00',
            'timezone' => 'America/Los_Angeles',
            'telegram_alerts_enabled' => '1',   // master switch for the Telegram digest
        ];

        foreach ($defaults as $key => $value) {
            if (! Setting::query()->where('key', $key)->exists()) {
                Setting::put($key, $value, sensitive: false);
            }
        }

        // Non-sensitive Google Ads settings (blank by default).
        foreach (['google_ads_client_id', 'google_ads_login_customer_id'] as $key) {
            if (! Setting::query()->where('key', $key)->exists()) {
                Setting::put($key, null, sensitive: false);
            }
        }

        // Sensitive integration credentials (encrypted at rest); blank by default.
        $sensitive = [
            'openphone_api_key', 'openphone_webhook_secret',
            'google_ads_developer_token', 'google_ads_client_secret', 'google_ads_refresh_token',
        ];
        foreach ($sensitive as $key) {
            if (! Setting::query()->where('key', $key)->exists()) {
                Setting::put($key, null, sensitive: true);
            }
        }
    }
}
