<?php

namespace App\Services\Messaging;

/**
 * Verifies the OpenPhone/Quo webhook signature.
 *
 * Header `openphone-signature` has the form: <scheme>;<version>;<timestamp>;<base64signature>
 * Signed data = timestamp + "." + rawBody. The signing secret is base64-encoded
 * and must be decoded to binary before use as the HMAC-SHA256 key. The digest is
 * base64-encoded and compared for exact equality.
 *
 * Reference: https://support.quo.com/core-concepts/integrations/webhooks
 */
class WebhookSignatureVerifier
{
    public function verify(?string $header, string $rawBody, ?string $base64Secret): bool
    {
        if (blank($header) || blank($base64Secret)) {
            return false;
        }

        $parts = explode(';', $header);
        if (count($parts) !== 4) {
            return false;
        }

        [, , $timestamp, $providedSignature] = $parts;

        $key = base64_decode($base64Secret, true);
        if ($key === false) {
            return false;
        }

        $signedData = $timestamp.'.'.$rawBody;
        $computed = base64_encode(hash_hmac('sha256', $signedData, $key, true));

        return hash_equals($computed, $providedSignature);
    }

    /** Helper for tests / tooling: produce a valid header for a given body. */
    public function sign(string $rawBody, string $base64Secret, string $timestamp): string
    {
        $key = base64_decode($base64Secret, true);
        $sig = base64_encode(hash_hmac('sha256', $timestamp.'.'.$rawBody, $key, true));

        return "hmac;1;{$timestamp};{$sig}";
    }
}
