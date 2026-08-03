<?php

namespace App\Support;

/**
 * Normalizes the smart punctuation a word processor, phone keyboard, or LLM
 * loves to insert down to its plain-ASCII equivalent, keeping SMS bodies in
 * the GSM-7 alphabet.
 *
 * Why this matters: a SINGLE non-GSM-7 character forces the WHOLE message into
 * UCS-2 encoding, which cuts the per-segment limit from 153 chars to 67. A
 * ~760-char "Job Complete" text then jumps from ~5 segments to ~12, and long
 * multi-part UCS-2 sends are exactly what carriers/A2P filters silently drop
 * even after OpenPhone/Quo's API returned success (202). A curly apostrophe in
 * the job_complete template is what caused real payment texts (Paul 626-390-7227,
 * Louis 310-694-6553) to never arrive while a manual Quo resend — which retypes
 * the punctuation — worked.
 *
 * These seven characters ALL have safe ASCII equivalents, so replacing them is
 * strictly a win. Legitimately-non-GSM letters (Spanish á í ó ú) are left
 * alone — they can't be normalized away and keep their own bodies in UCS-2,
 * which is unavoidable; this only strips the *gratuitous* smart punctuation.
 *
 * The single source of truth used by the template editor (MessageTemplateForm),
 * the queue-time render path (TemplateRenderer::render), and the one-off
 * backfill migration.
 */
class GsmText
{
    /** Smart char => ASCII replacement. */
    public const REPLACEMENTS = [
        "\u{2018}" => "'",   // ‘ left single quote
        "\u{2019}" => "'",   // ’ right single quote / apostrophe
        "\u{201C}" => '"',   // “ left double quote
        "\u{201D}" => '"',   // ” right double quote
        "\u{2013}" => '-',   // – en dash
        "\u{2014}" => '-',   // — em dash
        "\u{2026}" => '...', // … ellipsis
    ];

    /** Replace every smart quote/dash/ellipsis with its ASCII equivalent. */
    public static function normalize(string $text): string
    {
        return strtr($text, self::REPLACEMENTS);
    }

    /** The smart characters actually present in $text (for validation messages). */
    public static function offendingChars(string $text): array
    {
        return array_values(array_filter(
            array_keys(self::REPLACEMENTS),
            fn (string $char) => str_contains($text, $char),
        ));
    }
}
