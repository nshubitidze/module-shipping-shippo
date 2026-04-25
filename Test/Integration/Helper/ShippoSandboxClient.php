<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Test\Integration\Helper;

/**
 * Helper that loads the Shippo sandbox API key from `~/.shippo-key`.
 *
 * Used by the integration tests to gate execution: when the file is absent
 * (e.g. CI without the secret), the helper returns null and the calling
 * test is skipped — never failed. Production code never reads this path.
 */
class ShippoSandboxClient
{
    public const KEY_FILE = '/.shippo-key';

    /**
     * Returns the sandbox API key from one of (in order):
     *   1. The `SHIPPO_API_KEY` environment variable (preferred when running
     *      inside Docker, where the host's ~/.shippo-key isn't visible).
     *   2. `~/.shippo-key` on the host filesystem.
     *
     * Returns null when neither source is available — the calling test is
     * expected to skip in that case rather than fail.
     */
    public static function loadKey(): ?string
    {
        $envKey = getenv('SHIPPO_API_KEY');
        if (is_string($envKey) && trim($envKey) !== '') {
            return trim($envKey);
        }

        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            return null;
        }
        $path = $home . self::KEY_FILE;
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }
        $key = trim($content);
        return $key === '' ? null : $key;
    }

    /**
     * Hard guard: refuse a `shippo_live_*` key. Integration tests must only
     * touch the sandbox.
     */
    public static function isSandboxKey(string $key): bool
    {
        return str_starts_with($key, 'shippo_test_');
    }
}
