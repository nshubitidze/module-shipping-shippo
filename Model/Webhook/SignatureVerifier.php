<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Model\Webhook;

/**
 * Verifies the `X-Shippo-Signature` HMAC on an incoming webhook body.
 *
 * Shippo signs webhook bodies with `HMAC-SHA256(body, shared_secret)`
 * emitting the result as lowercase hex. We compare timing-safe via
 * {@see hash_equals()} so attackers cannot side-channel the first
 * differing byte.
 *
 * If either the provided signature or the shared secret is empty we
 * bail out explicitly instead of computing `hash_hmac('sha256', …, '')`
 * and returning `true` on a crafted replay.
 */
class SignatureVerifier
{
    public function verify(string $rawBody, string $providedSignature, string $secret): bool
    {
        if ($providedSignature === '' || $secret === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $providedSignature);
    }
}
