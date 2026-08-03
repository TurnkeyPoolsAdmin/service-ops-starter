<?php

namespace App\Services\AiScheduling;

use App\Models\Warehouse;
use Illuminate\Support\Collection;

/**
 * Turns a technician's free-text answer to "which warehouse should we order
 * the part from?" into an actual Warehouse row — see
 * docs/ai-scheduling-spec.md §4.5.
 *
 * Deterministic first, exactly like ReplyInterpreter: our warehouse names are
 * distinctive enough ("Mygrant Glass - San Fernando", "Pilkington - Commerce")
 * that a tech naming one is usually matchable for free. The LLM is only asked
 * when scoring is ambiguous, and even then it picks an INDEX out of the real
 * list — it can never invent a warehouse we don't have.
 *
 * Returning null is a perfectly good outcome: the job simply keeps no parts
 * source and the dispatcher picks one when they order the part (the Order-part
 * modal already asks for it). Nothing here ever guesses.
 */
final class WarehouseResolver
{
    /**
     * Words that appear in so many of our warehouse names they carry no
     * signal — matching on them alone would tie every supplier together.
     */
    private const NOISE = [
        'glass', 'corporation', 'corp', 'inc', 'llc', 'company', 'co',
        'warehouse', 'the', 'de', 'del', 'la', 'el', 'los', 'en',
    ];

    /**
     * Nicknames the crew actually uses that don't appear in the stored name.
     * Keyed by the PartsSupplier value on the warehouse row, so adding a
     * warehouse for an existing supplier picks these up for free.
     *
     * @var array<string, list<string>>
     */
    private const SUPPLIER_ALIASES = [
        'igc' => ['igc'],
        'mygrant' => ['mygrant', 'migrant', 'my grant'],
    ];

    public function __construct(private readonly ?LlmClassifier $classifier = null) {}

    /** The warehouses a tech can pick from — active only, in a stable order. */
    public function options(): Collection
    {
        return Warehouse::query()->where('active', true)->orderBy('name')->get();
    }

    /**
     * Resolve a reply to one warehouse, or null when we can't tell. $usedLlm
     * is set so the transcript can record whether this cost an API call.
     */
    public function resolve(string $reply, ?bool &$usedLlm = null): ?Warehouse
    {
        $usedLlm = false;
        $options = $this->options();

        if ($options->isEmpty()) {
            return null;
        }

        $deterministic = self::match($reply, $options);
        if ($deterministic !== null) {
            return $deterministic;
        }

        if ($this->classifier === null) {
            return null;
        }

        $usedLlm = true;
        $index = $this->classifier->classifyWarehouseReply($reply, $options->pluck('name')->all());

        // 1-based, and bounded by the list we supplied — an out-of-range answer
        // is a model error, not a warehouse.
        return $index !== null && $index >= 1 && $index <= $options->count()
            ? $options->values()->get($index - 1)
            : null;
    }

    /**
     * The free pass: score each warehouse by how many of its distinctive words
     * (plus its supplier's nicknames) the reply contains, and take the winner
     * only if it is a UNIQUE high scorer. "mygrant san fernando" beats every
     * other Mygrant branch; a bare "mygrant" ties them all and returns null,
     * which is the honest answer.
     */
    public static function match(string $reply, Collection $warehouses): ?Warehouse
    {
        $normalized = self::normalize($reply);

        if ($normalized === '') {
            return null;
        }

        $scores = $warehouses->map(fn (Warehouse $w) => [
            'warehouse' => $w,
            'score' => self::score($normalized, $w),
        ])->filter(fn (array $row) => $row['score'] > 0)->values();

        if ($scores->isEmpty()) {
            return null;
        }

        $best = $scores->max('score');
        $winners = $scores->where('score', $best);

        return $winners->count() === 1 ? $winners->first()['warehouse'] : null;
    }

    /** How many of this warehouse's distinctive terms the reply mentions. */
    private static function score(string $normalizedReply, Warehouse $warehouse): int
    {
        $replyWords = explode(' ', $normalizedReply);
        $score = 0;

        foreach (self::termsFor($warehouse) as $term) {
            // Multi-word aliases ("my grant") are matched against the whole
            // string; single words must land on a word boundary so "la" inside
            // "puente" can't score.
            $hit = str_contains($term, ' ')
                ? str_contains($normalizedReply, $term)
                : in_array($term, $replyWords, true);

            if ($hit) {
                $score++;
            }
        }

        return $score;
    }

    /** @return list<string> the distinctive words of a warehouse name, plus supplier nicknames. */
    private static function termsFor(Warehouse $warehouse): array
    {
        $words = array_values(array_filter(
            explode(' ', self::normalize((string) $warehouse->name)),
            fn (string $w) => $w !== '' && ! in_array($w, self::NOISE, true),
        ));

        $aliases = self::SUPPLIER_ALIASES[$warehouse->supplier?->value] ?? [];

        return array_values(array_unique([...$words, ...$aliases]));
    }

    /** Lowercase, accent-folded, punctuation stripped, single-spaced. */
    private static function normalize(string $text): string
    {
        $lower = mb_strtolower(trim($text));
        $folded = strtr($lower, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
        ]);

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9 ]+/', ' ', $folded)));
    }
}
