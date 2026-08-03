<?php

namespace App\Services\AiScheduling;

/**
 * Classifies an SMS reply during an AI scheduling negotiation. The only
 * boundary the LLM crosses in this feature — it classifies, it never writes
 * an outbound message or a job (see docs/ai-scheduling-spec.md §0). Kept as
 * an interface so the model/provider can be swapped without touching
 * ReplyInterpreter or anything upstream of it.
 */
interface LlmClassifier
{
    /**
     * Classify a technician's SMS reply to a job offer. $priorTurns is
     * caller-formatted lines of negotiation history (oldest first), already
     * rendered as plain text — this interface stays agnostic of how a turn
     * is modeled. Returns null on any transport/parse/API failure (the
     * implementation logs it) rather than throwing; the caller treats null
     * as "could not classify" and escalates.
     *
     * @param  list<string>  $priorTurns
     */
    public function classifyTechReply(
        string $reply,
        string $constraintDescription,
        string $ourLastMessage,
        array $priorTurns,
        string $today,
    ): ?ReplyIntent;

    /**
     * Classify a CUSTOMER's SMS reply while a scheduling session is live —
     * are they confirming the time we're working toward, asking for a
     * different one, or saying something else entirely? Shares ReplyIntent's
     * vocabulary rather than inventing a second one:
     *
     *   accept   = that time works / yes
     *   counter  = they want a different day or time (date/time carry it)
     *   decline  = they no longer want the job at all
     *   question = they're asking us something
     *   unclear  = can't tell
     *
     * Same null-on-failure contract as classifyTechReply().
     */
    public function classifyCustomerReply(
        string $reply,
        string $constraintDescription,
        string $today,
    ): ?ReplyIntent;

    /**
     * Which of OUR warehouses did the tech just name? $warehouseNames is the
     * real, ordered list; the answer is its **1-based index**, so the model
     * picks from what exists and can never invent a supplier we don't buy
     * from (the same bounded-candidates shape as LlmMatchSuggester).
     *
     * Returns null when it can't tell, as well as on any transport/parse/API
     * failure — the caller leaves the job's warehouse unset for a human
     * rather than guessing.
     *
     * @param  list<string>  $warehouseNames
     */
    public function classifyWarehouseReply(string $reply, array $warehouseNames): ?int;
}
