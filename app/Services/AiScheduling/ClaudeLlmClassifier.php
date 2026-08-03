<?php

namespace App\Services\AiScheduling;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Classifies a technician's SMS reply via the Anthropic Messages API. Same
 * calling pattern as App\Services\LlmMatchSuggester (raw Http, no SDK,
 * x-api-key from the encrypted settings table, manual status/JSON handling,
 * never throws) — reused deliberately so every Claude-backed feature in this
 * app fails the same way. See docs/ai-scheduling-spec.md §4.1.
 */
class ClaudeLlmClassifier implements LlmClassifier
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    public function enabled(): bool
    {
        return Setting::get('ai_scheduling_enabled', '0') === '1' && filled(Setting::get('anthropic_api_key'));
    }

    public function classifyTechReply(
        string $reply,
        string $constraintDescription,
        string $ourLastMessage,
        array $priorTurns,
        string $today,
    ): ?ReplyIntent {
        if (! $this->enabled()) {
            return null;
        }

        return $this->ask(self::buildTechPrompt($reply, $constraintDescription, $ourLastMessage, $priorTurns, $today));
    }

    public function classifyCustomerReply(
        string $reply,
        string $constraintDescription,
        string $today,
    ): ?ReplyIntent {
        if (! $this->enabled()) {
            return null;
        }

        return $this->ask(self::buildCustomerPrompt($reply, $constraintDescription, $today));
    }

    public function classifyWarehouseReply(string $reply, array $warehouseNames): ?int
    {
        if (! $this->enabled() || $warehouseNames === []) {
            return null;
        }

        $json = $this->askJson(self::buildWarehousePrompt($reply, $warehouseNames));
        $index = $json['index'] ?? null;

        // A string "3" is fine; anything non-numeric, or 0/null for "can't
        // tell", resolves to no warehouse.
        return is_numeric($index) && (int) $index >= 1 ? (int) $index : null;
    }

    /** One Messages API call, returning a parsed intent or null on any failure (logged). */
    private function ask(string $prompt): ?ReplyIntent
    {
        $json = $this->askJson($prompt);

        return $json !== null ? ReplyIntent::fromLlmJson($json) : null;
    }

    /** The raw call: decoded JSON object, or null on any failure (logged). */
    private function askJson(string $prompt): ?array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => (string) Setting::get('anthropic_api_key'),
                'anthropic-version' => '2023-06-01',
            ])->timeout(30)->post(self::ENDPOINT, [
                'model' => (string) config('glass.ai_scheduling.model', 'claude-haiku-4-5-20251001'),
                'max_tokens' => 200,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);
        } catch (\Throwable $e) {
            Log::error('AI scheduling classifier call failed', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::error('AI scheduling classifier API error', [
                'status' => $response->status(),
                'body' => $response->json('error.message'),
            ]);

            return null;
        }

        $text = $response->json('content.0.text');

        return self::parseJson(is_string($text) ? $text : '');
    }

    private static function buildTechPrompt(
        string $reply,
        string $constraintDescription,
        string $ourLastMessage,
        array $priorTurns,
        string $today,
    ): string {
        $priorLines = $priorTurns === [] ? '' : implode("\n", $priorTurns)."\n";

        return <<<TXT
        You classify a technician's SMS reply to a job offer. The tech may write in Spanish or English.
        Output ONLY a JSON object, no prose.

        Today is {$today} (America/Los_Angeles).
        The customer requires: {$constraintDescription}
        We texted the tech: "{$ourLastMessage}"
        {$priorLines}The tech replied: "{$reply}"

        {"intent":"accept|counter|decline|question|unclear",
         "date":"YYYY-MM-DD or null","time":"HH:MM 24h or null",
         "asks":"<their question, or null>","confidence":0.0-1.0,"reason":"<8 words>"}

        Rules:
        - "accept" ONLY if they clearly agree. If they name any different time, use "counter" with that time.
        - "decline" if they cannot do it at all, or cannot do that day.
        - "question" if they are asking us something (which shop, what car, how much).
        - "unclear" if you cannot tell. Do not guess.
        - A reply that is just a REACTION to our message - a thumbs up, or a relay like
          'Liked "..."' / '👍 a "..."' quoting what we sent - is an "accept". A thumbs
          down or 'Disliked "..."' is a "decline". Any other reaction is "unclear".
        TXT;
    }

    private static function buildCustomerPrompt(
        string $reply,
        string $constraintDescription,
        string $today,
    ): string {
        return <<<TXT
        You classify a customer's SMS reply about scheduling their auto glass appointment.
        The customer may write in Spanish or English. Output ONLY a JSON object, no prose.

        Today is {$today} (America/Los_Angeles).
        We are currently working toward: {$constraintDescription}
        The customer replied: "{$reply}"

        {"intent":"accept|counter|decline|question|unclear",
         "date":"YYYY-MM-DD or null","time":"HH:MM 24h or null",
         "asks":"<their question, or null>","confidence":0.0-1.0,"reason":"<8 words>"}

        Rules:
        - "accept" if they confirm that time works.
        - "counter" if they want a DIFFERENT day or time. Put it in date/time.
        - "decline" if they no longer want the service at all.
        - "question" if they are asking us something (price, warranty, how long it takes).
        - "unclear" if you cannot tell, or the message is not about scheduling. Do not guess.
        TXT;
    }

    /**
     * The tech names a shop; we need one of OUR rows. The list is numbered and
     * the answer is an index into it, so the model chooses rather than writes
     * — a name it invented can't reach the job.
     *
     * @param  list<string>  $warehouseNames
     */
    private static function buildWarehousePrompt(string $reply, array $warehouseNames): string
    {
        $list = implode("\n", array_map(
            fn (int $i, string $name) => ($i + 1).'. '.$name,
            array_keys($warehouseNames),
            $warehouseNames,
        ));

        return <<<TXT
        A technician was asked which warehouse we should order a glass part from.
        They may write in Spanish or English, and will often abbreviate or use a nickname
        ("mygrant SF", "the one in Compton", "IGC industry").
        Output ONLY a JSON object, no prose.

        Our warehouses:
        {$list}

        The tech replied: "{$reply}"

        {"index":<the number of the warehouse they mean, or null>,"confidence":0.0-1.0}

        Rules:
        - Answer with a number from the list above and nothing else.
        - null if you cannot tell, if they named somewhere not on the list, or if the
          reply is not about a warehouse at all. Do not guess between two branches of
          the same supplier.
        TXT;
    }

    private static function parseJson(string $text): ?array
    {
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $decoded = json_decode($m[0], true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }
}
