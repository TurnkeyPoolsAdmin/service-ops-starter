<?php

use App\Models\Setting;
use App\Services\AiScheduling\ClaudeLlmClassifier;
use App\Services\AiScheduling\LlmClassifier;
use App\Services\AiScheduling\ReplyIntent;
use App\Services\AiScheduling\ReplyInterpreter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

function enableAiScheduling(): void
{
    Setting::put('ai_scheduling_enabled', '1', sensitive: false);
    Setting::put('anthropic_api_key', 'sk-ant-test', sensitive: true);
}

function fakeClaudeReply(array $decision): void
{
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode($decision)]],
        ]),
    ]);
}

/** Records what it was asked and returns a canned intent, without touching the network. */
function scriptedClassifier(?ReplyIntent $result): LlmClassifier
{
    return new class($result) implements LlmClassifier
    {
        public array $calls = [];

        public function __construct(private readonly ?ReplyIntent $result) {}

        public function classifyTechReply(string $reply, string $constraintDescription, string $ourLastMessage, array $priorTurns, string $today): ?ReplyIntent
        {
            $this->calls[] = compact('reply', 'constraintDescription', 'ourLastMessage', 'priorTurns', 'today');

            return $this->result;
        }

        public function classifyCustomerReply(string $reply, string $constraintDescription, string $today): ?ReplyIntent
        {
            $this->calls[] = compact('reply', 'constraintDescription', 'today');

            return $this->result;
        }

        public function classifyWarehouseReply(string $reply, array $warehouseNames): ?int
        {
            $this->calls[] = compact('reply', 'warehouseNames');

            return null;
        }
    };
}

// ---- ReplyInterpreter::fastPath — deterministic, no LLM ----

it('fast-paths common accept phrases in English and Spanish, case/accent/punctuation insensitive', function () {
    foreach (['si', 'Sí!', 'SI.', 'yes', 'Ok', 'okay', 'listo', 'dale', 'claro', 'perfecto', 'de acuerdo', 'Yes I can', 'i can'] as $phrase) {
        $intent = ReplyInterpreter::fastPath($phrase);
        expect($intent)->not->toBeNull("expected '{$phrase}' to fast-path")
            ->and($intent->intent)->toBe(ReplyIntent::ACCEPT)
            ->and($intent->usedLlm)->toBeFalse()
            ->and($intent->confidence)->toBe(1.0);
    }
});

it('fast-paths a thumbs-up emoji as accept', function () {
    $intent = ReplyInterpreter::fastPath("\u{1F44D}");
    expect($intent->intent)->toBe(ReplyIntent::ACCEPT);
});

// ---- reactions: a tech who taps instead of typing ----

/**
 * The reply that started this (2026-07-27): Alex tapped a thumbs up on the
 * Spanish job offer, Quo relayed it as the emoji plus the whole quoted
 * message -- padded with a zero-width space and a hair space, so even a bare
 * "is this a thumbs up?" check missed it -- and the AI moved on and gave the
 * job to somebody else.
 */
it('reads a relayed tapback on our offer as a positive reaction', function () {
    $relayed = "\u{1F44D}\u{200B} a \u{201C}\u{200A}Hola, tengo un trabajo para lunes, 27 de julio.\n\n"
        ."Veh\u{ED}culo: 2018 Kia Sportage\nTrabajo: Windshield\nCiudad: Los Angeles\n\n"
        ."Ya tienes un trabajo ese d\u{ED}a. \u{BF}Lo puedes hacer?\u{201D}";

    expect(ReplyInterpreter::reactionSentiment($relayed))->toBe(ReplyInterpreter::REACTION_POSITIVE)
        ->and(ReplyInterpreter::fastPath($relayed)->intent)->toBe(ReplyIntent::ACCEPT);
});

it('reads the other reaction relay formats, bare or spelled out', function () {
    foreach ([
        "\u{1F44D}",                          // bare thumbs up
        "\u{1F44D}\u{200B}",                  // ... with the invisible padding
        "\u{1F44C}",                          // ok hand
        "\u{2764}\u{FE0F}",                   // the "Loved" tapback
        'Liked "Hi, I have a job for Monday"',
        'Loved "Hi, I have a job for Monday"',
        "Le gust\u{F3} \u{201C}Hola, tengo un trabajo\u{201D}",
        "\u{1F44D} to \"Hi, I have a job for Monday\"",
    ] as $reply) {
        expect(ReplyInterpreter::reactionSentiment($reply))
            ->toBe(ReplyInterpreter::REACTION_POSITIVE, "expected a positive reaction for: {$reply}");
    }

    foreach (["\u{1F44E}", 'Disliked "Hi, I have a job for Monday"', "\u{1F44E} a \u{201C}Hola\u{201D}"] as $reply) {
        expect(ReplyInterpreter::reactionSentiment($reply))
            ->toBe(ReplyInterpreter::REACTION_NEGATIVE, "expected a negative reaction for: {$reply}");
        expect(ReplyInterpreter::fastPath($reply)->intent)->toBe(ReplyIntent::DECLINE);
    }
});

it('leaves anything that is not a bare reaction to the model', function () {
    foreach ([
        "\u{1F44D} but only at 2pm",                        // a counter-offer, not an accept
        'Laughed at "Hi, I have a job for Monday"',          // we do not guess what a laugh means
        'Emphasized "Hi, I have a job for Monday"',
        "\u{2753} a \u{201C}Hola, tengo un trabajo\u{201D}", // a question mark reaction
        'Can you send me the "VIN" first?',                  // an ordinary message that quotes something
    ] as $reply) {
        expect(ReplyInterpreter::reactionSentiment($reply))->toBeNull("expected no reaction for: {$reply}");
        expect(ReplyInterpreter::fastPath($reply))->toBeNull();
    }
});

it('fast-paths common decline phrases in English and Spanish', function () {
    foreach (['no', 'No.', 'No puedo', 'estoy ocupado', "can't", 'cant', 'busy'] as $phrase) {
        $intent = ReplyInterpreter::fastPath($phrase);
        expect($intent)->not->toBeNull("expected '{$phrase}' to fast-path")
            ->and($intent->intent)->toBe(ReplyIntent::DECLINE);
    }
});

it('does not fast-path a longer or ambiguous reply', function () {
    expect(ReplyInterpreter::fastPath('no puedo hoy pero manana si'))->toBeNull()
        ->and(ReplyInterpreter::fastPath('maybe around 2?'))->toBeNull()
        ->and(ReplyInterpreter::fastPath(''))->toBeNull()
        ->and(ReplyInterpreter::fastPath('   '))->toBeNull();
});

// ---- ReplyInterpreter::interpretTechReply — wiring ----

it('returns the fast-path result without ever calling the classifier', function () {
    $classifier = scriptedClassifier(null);
    $interpreter = new ReplyInterpreter($classifier);

    $intent = $interpreter->interpretTechReply('si', 'Tuesday morning', 'can you do it?', [], '2026-07-22');

    expect($intent->intent)->toBe(ReplyIntent::ACCEPT)
        ->and($classifier->calls)->toBe([]);
});

it('falls back to the classifier for a non-fast-path reply and passes context through', function () {
    $counter = ReplyIntent::fromLlmJson(['intent' => 'counter', 'date' => '2026-07-22', 'time' => '14:00', 'confidence' => 0.9, 'reason' => 'proposed 2pm']);
    $classifier = scriptedClassifier($counter);
    $interpreter = new ReplyInterpreter($classifier);

    $intent = $interpreter->interpretTechReply(
        'I can do 2pm instead',
        'Tuesday morning (8am-12pm)',
        'Can you take it Tuesday morning?',
        ['[tech] asked something else'],
        '2026-07-22',
    );

    expect($intent->intent)->toBe(ReplyIntent::COUNTER)
        ->and($intent->time)->toBe('14:00')
        ->and($classifier->calls)->toHaveCount(1)
        ->and($classifier->calls[0]['reply'])->toBe('I can do 2pm instead')
        ->and($classifier->calls[0]['constraintDescription'])->toBe('Tuesday morning (8am-12pm)');
});

it('returns unclear when no classifier is wired and the reply does not fast-path', function () {
    $interpreter = new ReplyInterpreter;

    $intent = $interpreter->interpretTechReply('maybe, let me check', '', '', [], '2026-07-22');

    expect($intent->intent)->toBe(ReplyIntent::UNCLEAR)
        ->and($intent->isConfident())->toBeFalse();
});

it('returns unclear when the classifier itself returns null (failed call)', function () {
    $interpreter = new ReplyInterpreter(scriptedClassifier(null));

    $intent = $interpreter->interpretTechReply('maybe, let me check', '', '', [], '2026-07-22');

    expect($intent->intent)->toBe(ReplyIntent::UNCLEAR);
});

// ---- ReplyIntent::fromLlmJson — defensive parsing ----

it('degrades an invalid intent value to unclear rather than accepting it', function () {
    $intent = ReplyIntent::fromLlmJson(['intent' => 'yes_please', 'confidence' => 0.9]);
    expect($intent->intent)->toBe(ReplyIntent::UNCLEAR);
});

it('clamps an out-of-range confidence into 0..1', function () {
    expect(ReplyIntent::fromLlmJson(['intent' => 'accept', 'confidence' => 5])->confidence)->toBe(1.0)
        ->and(ReplyIntent::fromLlmJson(['intent' => 'accept', 'confidence' => -2])->confidence)->toBe(0.0);
});

it('rejects a malformed date/time rather than passing it through', function () {
    $intent = ReplyIntent::fromLlmJson(['intent' => 'counter', 'date' => 'tomorrow', 'time' => '2pm', 'confidence' => 0.8]);
    expect($intent->date)->toBeNull()->and($intent->time)->toBeNull();
});

it('isConfident respects the configured minimum', function () {
    config(['glass.ai_scheduling.min_confidence' => 0.6]);

    expect(ReplyIntent::fromLlmJson(['intent' => 'accept', 'confidence' => 0.59])->isConfident())->toBeFalse()
        ->and(ReplyIntent::fromLlmJson(['intent' => 'accept', 'confidence' => 0.6])->isConfident())->toBeTrue();
});

// ---- ClaudeLlmClassifier — real HTTP shape, faked network ----

it('is disabled and returns null without calling the API until enabled with a key', function () {
    $classifier = new ClaudeLlmClassifier;
    expect($classifier->enabled())->toBeFalse();

    expect($classifier->classifyTechReply('hola', 'morning', 'can you?', [], '2026-07-22'))->toBeNull();
    Http::assertNothingSent();
});

it('classifies a real reply once enabled', function () {
    enableAiScheduling();
    fakeClaudeReply(['intent' => 'accept', 'confidence' => 0.95, 'reason' => 'clear yes']);

    $intent = (new ClaudeLlmClassifier)->classifyTechReply('sounds good, I will be there', 'Tuesday morning', 'Can you take it Tuesday morning?', [], '2026-07-22');

    expect($intent->intent)->toBe(ReplyIntent::ACCEPT)
        ->and($intent->usedLlm)->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'https://api.anthropic.com/v1/messages'
        && $request['model'] === 'claude-haiku-4-5-20251001'
        && $request->hasHeader('x-api-key', 'sk-ant-test'));
});

it('returns null on a non-200 API response', function () {
    enableAiScheduling();
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'bad key']], 401)]);

    expect((new ClaudeLlmClassifier)->classifyTechReply('si', 'morning', 'can you?', [], '2026-07-22'))->toBeNull();
});

it('returns null on a transport failure', function () {
    enableAiScheduling();
    Http::fake(['api.anthropic.com/*' => fn () => throw new ConnectionException('timed out')]);

    expect((new ClaudeLlmClassifier)->classifyTechReply('si', 'morning', 'can you?', [], '2026-07-22'))->toBeNull();
});

it('returns null when the model reply has no parseable JSON', function () {
    enableAiScheduling();
    Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'sure thing, no json here']]])]);

    expect((new ClaudeLlmClassifier)->classifyTechReply('si', 'morning', 'can you?', [], '2026-07-22'))->toBeNull();
});

// ---- Recorded real-world-shaped EN/ES/Spanglish replies through the full interpreter ----

it('interprets a realistic set of recorded-style tech replies via the LLM path', function (string $reply, array $decision, string $expectedIntent) {
    enableAiScheduling();
    fakeClaudeReply($decision);

    $interpreter = new ReplyInterpreter(new ClaudeLlmClassifier);
    $intent = $interpreter->interpretTechReply($reply, 'Tuesday morning (8am-12pm)', 'Can you take it Tuesday morning?', [], '2026-07-22');

    expect($intent->intent)->toBe($expectedIntent);
})->with([
    'spanglish counter' => [
        'no puedo en la mañana pero at 2pm si',
        ['intent' => 'counter', 'date' => '2026-07-22', 'time' => '14:00', 'confidence' => 0.85, 'reason' => 'offered 2pm instead'],
        ReplyIntent::COUNTER,
    ],
    'english question' => [
        'which shop is the part coming from?',
        ['intent' => 'question', 'asks' => 'which shop is the part from', 'confidence' => 0.9, 'reason' => 'asking about part source'],
        ReplyIntent::QUESTION,
    ],
    'spanish decline with reason' => [
        'no puedo ese dia, tengo otro trabajo todo el dia',
        ['intent' => 'decline', 'confidence' => 0.9, 'reason' => 'booked all day'],
        ReplyIntent::DECLINE,
    ],
    'vague reply' => [
        'hmm let me see',
        ['intent' => 'unclear', 'confidence' => 0.3, 'reason' => 'noncommittal'],
        ReplyIntent::UNCLEAR,
    ],
]);
