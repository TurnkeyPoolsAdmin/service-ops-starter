<?php

use App\Models\Setting;
use App\Services\Messaging\Providers\LogMessageProvider;
use App\Services\Messaging\Providers\MessageProvider;
use App\Services\Messaging\Providers\OpenPhoneProvider;

beforeEach(function () {
    // Simulate the one configuration where real texts would otherwise send.
    config(['glass.message_provider' => 'openphone']);
    $this->app['env'] = 'production';
});

it('sends via the real provider in prod when not paused', function () {
    Setting::put('outgoing_sms_paused', '0');

    expect(app(MessageProvider::class))->toBeInstanceOf(OpenPhoneProvider::class);
});

it('falls back to the log provider when outgoing texts are paused', function () {
    Setting::put('outgoing_sms_paused', '1');

    expect(app(MessageProvider::class))->toBeInstanceOf(LogMessageProvider::class);
});
