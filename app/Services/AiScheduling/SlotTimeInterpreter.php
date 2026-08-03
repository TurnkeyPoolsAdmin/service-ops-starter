<?php

namespace App\Services\AiScheduling;

/**
 * LLM fallback for a customer's requested-time free text that the
 * deterministic parser in RequestedSlotParser can't make sense of (e.g. "right
 * after school", "cuando pueda"). Implemented via Claude in phase 4
 * (LlmClassifier); RequestedSlotParser works without one wired in, it just
 * falls back to a flexible constraint.
 */
interface SlotTimeInterpreter
{
    /** Interpret free text against a fixed date, or null if it says nothing usable about time. */
    public function interpretRequestedTime(string $freeText, string $date): ?SchedulingConstraint;
}
