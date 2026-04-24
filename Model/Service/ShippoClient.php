<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Model\Service;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Phrase;
use Psr\Log\LoggerInterface;
use Shubo\ShippingCore\Exception\AuthException;
use Shubo\ShippingCore\Exception\NetworkException;
use Shubo\ShippingCore\Exception\RateLimitedException;
use Shubo\ShippingCore\Exception\ShipmentDispatchFailedException;
use Shubo\ShippingCore\Exception\TransientHttpException;
use Shubo\ShippingShippo\Model\Data\Config;

/**
 * Thin Shippo REST client used by the adapter + webhook stack.
 *
 * Transport is the stock Magento Curl client. Every request:
 *   - sets Authorization: ShippoToken {key} (decrypted via Config)
 *   - sets Content-Type / Accept: application/json
 *   - on 5xx, retries once with 500ms back-off (test hook replaces sleep)
 *   - translates carrier errors to the ShippingCore exception hierarchy
 *   - logs with the Authorization header REDACTED so secrets never land
 *     in a log aggregator
 *
 * The class intentionally owns no domain logic — mapping lives in
 * AddressMapper / ParcelMapper / StatusMapper. That keeps the test
 * surface small and the retry semantics reusable across endpoints.
 *
 * @phpstan-type SleepFn callable(int): void
 */
class ShippoClient
{
    private const RETRY_DELAY_MS = 500;
    private const MAX_RETRIES = 1;

    /** @var SleepFn */
    private $sleeper;

    /**
     * @param SleepFn|null $sleeper Injected sleep hook for retry back-off; null
     *     uses usleep. Tests pass a spy so the suite never actually waits.
     */
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Config $config,
        private readonly LoggerInterface $logger,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * POST /shipments.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createShipment(array $payload): array
    {
        return $this->request('POST', '/shipments', $payload);
    }

    /**
     * POST /transactions — purchases a label for the chosen rate.
     *
     * @return array<string, mixed>
     */
    public function createTransaction(string $rateId): array
    {
        return $this->request('POST', '/transactions', [
            'rate' => $rateId,
            'label_file_type' => 'PDF',
            'async' => false,
        ]);
    }

    /**
     * GET /tracks/{carrier}/{tracking_number}.
     *
     * @return array<string, mixed>
     */
    public function getTrack(string $carrierToken, string $trackingNumber): array
    {
        $path = sprintf('/tracks/%s/%s', rawurlencode($carrierToken), rawurlencode($trackingNumber));
        return $this->request('GET', $path, null);
    }

    /**
     * POST /refunds.
     *
     * @return array<string, mixed>
     */
    public function createRefund(string $transactionId): array
    {
        return $this->request('POST', '/refunds', ['transaction' => $transactionId]);
    }

    /**
     * Raw GET for binary payloads (label PDF downloads). Returns the exact
     * byte string from the response body without JSON decoding.
     */
    public function fetchBinary(string $url): string
    {
        $curl = $this->curlFactory->create();
        $this->applyAuthHeaders($curl);
        $curl->setOptions([CURLOPT_TIMEOUT => 30]);
        $this->logger->debug(
            'Shippo fetchBinary GET',
            ['url' => $url, 'headers' => $this->redactedHeaders()],
        );

        try {
            $curl->get($url);
        } catch (\Throwable $e) {
            throw new NetworkException(
                __('Shippo binary fetch failed at transport layer: %1', $e->getMessage()),
                $e instanceof \Exception ? $e : null,
            );
        }

        $status = (int)$curl->getStatus();
        if ($status < 200 || $status >= 300) {
            throw TransientHttpException::create(
                $status,
                sprintf('Shippo binary fetch returned HTTP %d', $status),
            );
        }

        return (string)$curl->getBody();
    }

    /**
     * Shared request path with retry + error translation.
     *
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload): array
    {
        $url = $this->config->getApiBaseUrl() . $path;
        $encoded = $payload === null ? '' : $this->encodePayload($payload);

        $attempts = 0;
        $lastStatus = 0;
        do {
            $attempts++;
            $curl = $this->curlFactory->create();
            $this->applyAuthHeaders($curl);
            $curl->setOptions([CURLOPT_TIMEOUT => 30]);

            $this->logger->debug(
                'Shippo ' . $method . ' ' . $path,
                ['url' => $url, 'headers' => $this->redactedHeaders()],
            );

            try {
                if ($method === 'GET') {
                    $curl->get($url);
                } else {
                    $curl->post($url, $encoded);
                }
            } catch (\Throwable $e) {
                throw new NetworkException(
                    __('Shippo request failed at transport layer: %1', $e->getMessage()),
                    $e instanceof \Exception ? $e : null,
                );
            }

            $status = (int)$curl->getStatus();
            $body = (string)$curl->getBody();
            $lastStatus = $status;

            if ($status >= 500 && $attempts <= self::MAX_RETRIES) {
                ($this->sleeper)(self::RETRY_DELAY_MS);
                continue;
            }

            return $this->handleResponse($status, $body);
        } while ($attempts <= self::MAX_RETRIES + 1);

        throw TransientHttpException::create(
            $lastStatus,
            sprintf('Shippo request exhausted retries at HTTP %d', $lastStatus),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function handleResponse(int $status, string $body): array
    {
        if ($status === 401 || $status === 403) {
            throw new AuthException(
                __('Shippo authentication failed: %1', $this->extractMessage($body, $status)),
            );
        }
        if ($status === 429) {
            throw new RateLimitedException(
                new Phrase('Shippo rate limit hit'),
                null,
            );
        }
        if ($status >= 500) {
            throw TransientHttpException::create(
                $status,
                sprintf('Shippo upstream error after retry: HTTP %d', $status),
            );
        }
        if ($status >= 400) {
            throw new ShipmentDispatchFailedException(
                __('Shippo request rejected: %1', $this->extractMessage($body, $status)),
            );
        }

        if ($body === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw TransientHttpException::create(
                $status,
                'Shippo response was not valid JSON: ' . $e->getMessage(),
            );
        }

        if (!is_array($decoded)) {
            throw TransientHttpException::create(
                $status,
                'Shippo response JSON was not an object/array.',
            );
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Pull the first user-facing message out of a Shippo error body. Shippo
     * conventionally returns `{ "messages": [{ "text": "..." }] }` on 4xx.
     * We fall back to a short body excerpt to avoid losing context, but
     * never echo the entire body into a log line.
     */
    private function extractMessage(string $body, int $status): string
    {
        if ($body === '') {
            return sprintf('HTTP %d (empty body)', $status);
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return substr($body, 0, 160);
        }
        if (is_array($decoded) && isset($decoded['messages']) && is_array($decoded['messages'])) {
            $first = $decoded['messages'][0] ?? null;
            if (is_array($first) && isset($first['text']) && is_string($first['text'])) {
                return $first['text'];
            }
        }
        if (is_array($decoded) && isset($decoded['detail']) && is_string($decoded['detail'])) {
            return $decoded['detail'];
        }
        return substr($body, 0, 160);
    }

    private function applyAuthHeaders(\Magento\Framework\HTTP\Client\Curl $curl): void
    {
        $curl->addHeader('Authorization', 'ShippoToken ' . $this->config->getApiKey());
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('Accept', 'application/json');
    }

    /**
     * @return array<string, string>
     */
    private function redactedHeaders(): array
    {
        return [
            'Authorization' => '[REDACTED]',
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encodePayload(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ShipmentDispatchFailedException(
                __('Unable to encode Shippo request payload: %1', $e->getMessage()),
                $e,
            );
        }
    }
}
