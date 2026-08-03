<?php

namespace App\Services\Messaging;

/**
 * Verifies the sales-dashboard intake webhook signature.
 *
 * Scheme (from the shared Sales -> Dispatch Intake Contract v1):
 *   X-Sales-Timestamp: unix seconds
 *   X-Sales-Signature: hex HMAC-SHA256 of "{timestamp}.{rawBody}" with a shared secret
 *
 * Deliberately NOT folded into WebhookSignatureVerifier: that one decodes a
 * base64 secret, reads a 4-part `<scheme>;<version>;<ts>;<sig>` header and
 * compares base64 digests — a different scheme end to end. Sharing the class
 * would have meant a mode flag on a security primitive.
 *
 * The ±300s replay window is enforced here so the controller has one thing to
 * ask ("is this request legitimate?") and one place answers it.
 */
class SalesIntakeSignatureVerifier
{
    /** Contract replay window, in seconds, either side of now. */
    public const TOLERANCE_SECONDS = 300;

    public function verify(?string $timestamp, ?string $signature, string $rawBody, ?string $secret): bool
    {
        if (blank($timestamp) || blank($signature) || blank($secret)) {
            return false;
        }

        if (! $this->timestampFresh($timestamp)) {
            return false;
        }

        $computed = hash_hmac('sha256', $timestamp.'.'.$rawBody, (string) $secret);

        return hash_equals($computed, (string) $signature);
    }

    /** Is the header timestamp inside the replay window? Non-numeric is never fresh. */
    public function timestampFresh(?string $timestamp): bool
    {
        if (! is_numeric($timestamp)) {
            return false;
        }

        return abs(now()->timestamp - (int) $timestamp) <= self::TOLERANCE_SECONDS;
    }

    /** Helper for tests / the sender-side docs: the signature for a body at a time. */
    public function sign(string $rawBody, string $secret, string|int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }
}
