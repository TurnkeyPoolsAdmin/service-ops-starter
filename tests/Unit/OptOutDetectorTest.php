<?php

use App\Services\Messaging\OptOutDetector;

beforeEach(function () {
    $this->detector = new OptOutDetector;
});

it('detects the classic keywords as standalone tokens', function (string $body) {
    expect($this->detector->isOptOut($body))->toBeTrue();
})->with([
    'STOP',
    'stop',
    'Stop.',
    'please STOP texting me',
    'UNSUBSCRIBE',
    'stopall',
    'optout',
]);

it('detects opt-out phrases anywhere in the message', function (string $body) {
    expect($this->detector->isOptOut($body))->toBeTrue();
})->with([
    'please remove me from this list',
    'do not text this number again',
    "don't text me",
    'I want to opt out',
]);

it('treats END, CANCEL and QUIT as opt-outs only when they are the whole message', function () {
    expect($this->detector->isOptOut('END'))->toBeTrue()
        ->and($this->detector->isOptOut('cancel'))->toBeTrue()
        ->and($this->detector->isOptOut('Quit.'))->toBeTrue()
        ->and($this->detector->isOptOut('Everything looks good on our end'))->toBeFalse()
        ->and($this->detector->isOptOut('Can we cancel the appointment and reschedule?'))->toBeFalse()
        ->and($this->detector->isOptOut('I had to quit work early today'))->toBeFalse();
});

it('ignores tapback reactions that echo our own message back', function (string $body) {
    expect($this->detector->isOptOut($body))->toBeFalse();
})->with([
    'Liked “Everything looks good on our end. Reply STOP to opt out.”',
    'Loved "Your total comes to $370. Reply STOP to opt out."',
    'Emphasized “We can cancel or reschedule anytime.”',
    'Laughed at “Reply STOP to opt out”',
    'Reacted 👍 to “Reply STOP to opt out”',
    'Removed a like from “Reply STOP to opt out”',
]);

it('still honors a real STOP even in a longer sentence', function () {
    expect($this->detector->isOptOut('stop texting me please'))->toBeTrue();
});

it('does not flag ordinary replies', function (string $body) {
    expect($this->detector->isOptOut($body))->toBeFalse();
})->with([
    'Yes, thanks',
    'Hi still waiting for link?',
    'Sounds good, see you then',
    '',
]);
