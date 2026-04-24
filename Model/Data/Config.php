<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Model\Data;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Typed accessor for the `shubo_shipping_shippo/*` config tree.
 *
 * All reads go through {@see ScopeConfigInterface}; secret values are
 * decrypted through {@see EncryptorInterface}. Defaults mirror the
 * design doc §4 so tests and production agree.
 */
class Config
{
    public const XML_PATH_MODE = 'shubo_shipping_shippo/api/mode';
    public const XML_PATH_KEY = 'shubo_shipping_shippo/api/key';
    public const XML_PATH_WEBHOOK_SECRET = 'shubo_shipping_shippo/api/webhook_secret';
    public const XML_PATH_ENABLED = 'shubo_shipping_shippo/api/enabled';
    public const XML_PATH_ALLOWED_CARRIERS = 'shubo_shipping_shippo/service/allowed_carriers';
    public const XML_PATH_RATE_CACHE_TTL = 'shubo_shipping_shippo/service/rate_cache_ttl';

    public const API_BASE_URL = 'https://api.goshippo.com';
    public const DEFAULT_MODE = 'test';
    public const DEFAULT_RATE_CACHE_TTL = 60;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE)
            || $this->readFlag(self::XML_PATH_ENABLED);
    }

    public function getMode(): string
    {
        $raw = $this->scopeConfig->getValue(self::XML_PATH_MODE, ScopeInterface::SCOPE_STORE);
        if (!is_string($raw) || $raw === '') {
            return self::DEFAULT_MODE;
        }
        return $raw;
    }

    /**
     * Decrypted Shippo API key. Empty string when unset.
     */
    public function getApiKey(): string
    {
        return $this->readSecret(self::XML_PATH_KEY);
    }

    /**
     * Decrypted shared webhook secret. Empty string when unset.
     */
    public function getWebhookSecret(): string
    {
        return $this->readSecret(self::XML_PATH_WEBHOOK_SECRET);
    }

    /**
     * Read a potentially-encrypted config value.
     *
     * Admin UI saves via the Encrypted backend model (ciphertext shaped
     * "n:n:base64"). `bin/magento config:set` bypasses the backend model
     * and stores the value plain. Handle both — decrypt only when the
     * stored value matches the Magento ciphertext shape; otherwise return
     * as-is so CLI-seeded test keys work without re-encryption.
     *
     * In `live` mode a plaintext value is a configuration error — a
     * `shippo_live_*` key sitting unencrypted in `core_config_data` is a
     * leak. Hard-fail instead of silently using it.
     *
     * @throws LocalizedException when a plaintext secret is read in live mode.
     */
    private function readSecret(string $path): string
    {
        $raw = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE);
        if (!is_string($raw) || $raw === '') {
            return '';
        }
        if (preg_match('/^\d+:\d+:/', $raw) === 1) {
            return (string)$this->encryptor->decrypt($raw);
        }
        if ($this->getMode() === 'live') {
            throw new LocalizedException(
                __(
                    'Shippo secret at %1 is stored in plaintext. Save it through the admin UI '
                    . 'or via `config:sensitive:set` to encrypt at rest before enabling live mode.',
                    $path,
                ),
            );
        }
        $this->logger?->warning(
            'Shippo secret read as plaintext (test mode only — must be encrypted before live rollout)',
            ['path' => $path],
        );
        return $raw;
    }

    /**
     * @return list<string>
     */
    public function getAllowedCarriers(): array
    {
        $raw = $this->scopeConfig->getValue(self::XML_PATH_ALLOWED_CARRIERS, ScopeInterface::SCOPE_STORE);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $parts = array_map('trim', explode(',', $raw));
        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    public function getRateCacheTtl(): int
    {
        $raw = $this->scopeConfig->getValue(self::XML_PATH_RATE_CACHE_TTL, ScopeInterface::SCOPE_STORE);
        if ($raw === null || $raw === '') {
            return self::DEFAULT_RATE_CACHE_TTL;
        }
        return (int)$raw;
    }

    public function getApiBaseUrl(): string
    {
        return self::API_BASE_URL;
    }

    private function readFlag(string $path): bool
    {
        $raw = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE);
        return in_array((string)$raw, ['1', 'true', 'yes'], true);
    }
}
