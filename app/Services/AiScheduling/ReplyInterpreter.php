<?php

namespace App\Services\AiScheduling;

/**
 * Classifies a technician's SMS reply: a deterministic keyword fast-path
 * first (free, instant, no model call — covers the bulk of real replies:
 * "si", "ok", "no puedo", ...), falling back to an injected LlmClassifier for
 * anything longer or ambiguous. See docs/ai-scheduling-spec.md §4.1.
 *
 * The classifier is optional (mirrors RequestedSlotParser's LLM-fallback
 * pattern from phase 1) — with none wired in, only the fast-path phrases are
 * recognized and everything else comes back `unclear`, which is always the
 * safe default (the state machine escalates rather than guesses).
 */
final class ReplyInterpreter
{
    public const REACTION_POSITIVE = 'positive';

    public const REACTION_NEGATIVE = 'negative';

    /**
     * Whole-message matches only (after normalize()) — a longer or
     * ambiguous reply is deliberately left to the LLM rather than matched on
     * a substring, so "no puedo hoy pero manana si" doesn't fast-path as a
     * decline.
     */
    private const ACCEPT_PHRASES = [
        'si', 'sí', 'yes', 'ok', 'okay', 'listo', 'dale', 'claro', 'perfecto',
        'va', 'simon', 'sale', 'de acuerdo', 'yes i can', 'i can', 'yep', 'yup',
    ];

    private const DECLINE_PHRASES = [
        'no', 'no puedo', 'nel', 'estoy ocupado', 'no puedo ese dia',
        'cant', "can't", 'busy',
    ];

    /**
     * Reaction emoji, split by what they mean as an answer to "can you take
     * this job?". Deliberately narrow: anything a tech could plausibly send
     * for a reason OTHER than agreeing (a laugh, a shrug, an exclamation) is
     * left off both lists so it falls through to the model instead.
     */
    private const POSITIVE_REACTIONS = [
        "\u{1F44D}", // thumbs up
        "\u{1F44C}", // ok hand
        "\u{2705}", "\u{2714}", "\u{2611}", // check marks
        "\u{1F4AF}", // 100
        "\u{1F64C}", // raised hands
        "\u{1F91D}", // handshake
        "\u{1FAE1}", // salute
        "\u{2764}", "\u{1F9E1}", "\u{1F49B}", "\u{1F49A}", "\u{1F499}", "\u{1F49C}", // the "Loved" tapback
    ];

    private const NEGATIVE_REACTIONS = ["\u{1F44E}", "\u{274C}", "\u{1F6AB}", "\u{26D4}"];

    /** Words a carrier/app uses when it spells a tapback out instead of relaying the emoji. */
    private const POSITIVE_RELAY_WORDS = ['liked', 'loved', 'me gusta', 'gusto', 'encanto'];

    private const NEGATIVE_RELAY_WORDS = ['disliked', 'no le gusto'];

    /**
     * Zero-width and variation-selector padding that reaction relays wrap
     * around the emoji and the quoted original. Quo's relay of an iPhone
     * tapback arrives as "\u{1F44D}\u{200B} a \u{201C}\u{200A}Hola, tengo un
     * trabajo...\u{201D}", so even a bare thumbs up is not a bare thumbs up.
     */
    private const INVISIBLE = [
        "\u{200B}", "\u{200C}", "\u{200D}", "\u{200A}", "\u{2009}", "\u{FEFF}", "\u{FE0F}", "\u{FE0E}",
    ];

    /** Opening quote characters a relayed reaction wraps the original message in. */
    private const QUOTE_CHARS = ['"', "'", "\u{201C}", "\u{2018}", "\u{201E}", "\u{00AB}"];

    public function __construct(private readonly ?LlmClassifier $classifier = null) {}

    public function interpretTechReply(
        string $reply,
        string $constraintDescription,
        string $ourLastMessage,
        array $priorTurns,
        string $today,
    ): ReplyIntent {
        $fastPath = self::fastPath($reply);
        if ($fastPath !== null) {
            return $fastPath;
        }

        if ($this->classifier !== null) {
            $classified = $this->classifier->classifyTechReply(
                $reply, $constraintDescription, $ourLastMessage, $priorTurns, $today,
            );
            if ($classified !== null) {
                return $classified;
            }
        }

        return ReplyIntent::unclear('no fast-path match and no classifier result');
    }

    /**
     * Classify a customer's reply during a live session. No fast-path here:
     * a bare "no" from a technician means "I can't take this job", but from a
     * customer it could mean cancelling the whole appointment — far too
     * consequential to decide on a keyword. Everything goes to the model, and
     * anything it can't classify escalates to a human.
     */
    public function interpretCustomerReply(
        string $reply,
        string $constraintDescription,
        string $today,
    ): ReplyIntent {
        $classified = $this->classifier?->classifyCustomerReply($reply, $constraintDescription, $today);

        return $classified ?? ReplyIntent::unclear('no classifier result for customer reply');
    }

    /** The deterministic pass alone — public so callers/tests can check fast-path coverage directly. */
    public static function fastPath(string $reply): ?ReplyIntent
    {
        $reaction = self::reactionSentiment($reply);
        if ($reaction === self::REACTION_POSITIVE) {
            return ReplyIntent::fastReactionAccept();
        }
        if ($reaction === self::REACTION_NEGATIVE) {
            return ReplyIntent::fastDecline();
        }

        $normalized = self::normalize($reply);
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, self::ACCEPT_PHRASES, true)) {
            return ReplyIntent::fastAccept();
        }

        if (in_array($normalized, self::DECLINE_PHRASES, true)) {
            return ReplyIntent::fastDecline();
        }

        return null;
    }

    /**
     * Is this reply a REACTION to a message of ours rather than a written
     * answer — a thumbs up, a "Liked ..." relay — and if so, does it read as
     * yes or no? Returns null for anything that isn't a bare reaction, which
     * includes a reaction with real words after it ("👍 but only at 2") — that
     * is a counter-offer and belongs to the model, not to a keyword match.
     *
     * Public because the state machine wants the fact separately from the
     * intent: a tech tapping 👍 is a weaker "yes" than a tech typing one, so
     * it always gets read back to them before anything is booked
     * (owner's call, 2026-07-27, after a tech's thumbs up was missed entirely
     * and the job went to somebody else).
     */
    public static function reactionSentiment(string $reply): ?string
    {
        $clean = trim(str_replace(self::INVISIBLE, '', $reply));

        if ($clean === '') {
            return null;
        }

        // "👍" / "👍👍" — the whole message is nothing but reaction emoji.
        $bare = self::sentimentOfEmojiRun($clean);
        if ($bare !== null) {
            return $bare;
        }

        // A relayed tapback: a short header, then the original message quoted
        // back. Anything longer than a header before that quote is a person
        // writing a sentence that happens to quote something.
        $quotes = preg_quote(implode('', self::QUOTE_CHARS), '/');

        if (preg_match('/^(.{0,40}?)['.$quotes.']/su', $clean, $m) !== 1) {
            return null;
        }

        return self::sentimentOfHeader($m[1]);
    }

    /** The sentiment when every character is a reaction emoji of one polarity, else null. */
    private static function sentimentOfEmojiRun(string $text): ?string
    {
        $chars = array_values(array_filter(
            mb_str_split($text),
            fn (string $c) => trim($c) !== '',
        ));

        if ($chars === []) {
            return null;
        }

        foreach ([self::REACTION_POSITIVE => self::POSITIVE_REACTIONS, self::REACTION_NEGATIVE => self::NEGATIVE_REACTIONS] as $sentiment => $set) {
            if (array_diff($chars, $set) === []) {
                return $sentiment;
            }
        }

        return null;
    }

    /**
     * The bit before the quoted original — "👍 a ", "Liked ", "Le gustó ".
     * Mixed or unrecognized signals (a "Laughed at", an "Emphasized") come
     * back null on purpose: we don't guess what a laugh means about a job.
     */
    private static function sentimentOfHeader(string $header): ?string
    {
        $folded = self::normalize($header);

        $positive = self::containsAny($header, self::POSITIVE_REACTIONS)
            || self::containsAny($folded, self::POSITIVE_RELAY_WORDS);
        $negative = self::containsAny($header, self::NEGATIVE_REACTIONS)
            || self::containsAny($folded, self::NEGATIVE_RELAY_WORDS);

        // Negative wins a tie, and it has to: every negative relay word
        // CONTAINS a positive one ("disliked" contains "liked", "no le gusto"
        // contains "gusto"), so a strict either/or would read a rejection as
        // ambiguous. Erring negative also errs safe — a wrong decline just
        // moves to the next tech, a wrong accept books a job.
        if ($negative) {
            return self::REACTION_NEGATIVE;
        }

        return $positive ? self::REACTION_POSITIVE : null;
    }

    /** @param  list<string>  $needles */
    private static function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Lowercase, accent-folded, trailing punctuation stripped — for whole-message keyword matching only. */
    private static function normalize(string $text): string
    {
        $lower = mb_strtolower(trim(str_replace(self::INVISIBLE, '', $text)));
        $folded = strtr($lower, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
            '¡' => '', '¿' => '',
        ]);

        return trim((string) preg_replace('/[.!?]+$/', '', $folded));
    }
}
