<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Psr\Log\LoggerInterface;
use Shubo\ShippingCore\Api\Data\Dto\ContactAddress;
use Shubo\ShippingCore\Api\Data\Dto\ParcelSpec;
use Shubo\ShippingCore\Api\Data\Dto\QuoteRequest;
use Shubo\ShippingCore\Api\Data\Dto\RateOption;
use Shubo\ShippingShippo\Model\Adapter\ShippoCarrierGateway;

/**
 * Magento carrier for Shippo — parallel subclass of AbstractCarrier.
 *
 * `_code = 'shippo'`, `_isFixed = false`. Each rate option returned by
 * {@see ShippoCarrierGateway} gets one Method row. The rate_object_id is
 * stored in the Method's data bag so the post-checkout pipeline can use it
 * to purchase the exact label the customer saw at checkout.
 *
 * Design: ~/module-shipping-shippo/docs/session-6-checkout-integration-design.md §2
 */
class ShippoMagentoCarrier extends AbstractCarrier implements CarrierInterface
{
    public const CARRIER_CODE = 'shippo';
    public const EVENT_RESOLVE_RATE_CONTEXT = 'shubo_shipping_resolve_rate_context';

    /**
     * @var string
     */
    protected $_code = 'shippo';

    /**
     * @var bool
     */
    protected $_isFixed = false;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        private readonly ResultFactory $rateResultFactory,
        private readonly MethodFactory $rateMethodFactory,
        private readonly ShippoCarrierGateway $gateway,
        private readonly EventManagerInterface $eventManager,
        array $data = [],
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    /**
     * @return array<string, string>
     */
    public function getAllowedMethods(): array
    {
        return ['shippo' => 'Shippo'];
    }

    /**
     * Collect Shippo rate options for the given Magento rate request.
     *
     * Returns false on every terminal condition (carrier disabled, no merchant
     * context, gateway exception, no rate options) so Magento omits Shippo from
     * the checkout rate list rather than blocking checkout entirely.
     *
     * @param RateRequest $request
     * @return Result|bool
     */
    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        $quoteRequest = $this->buildQuoteRequest($request);
        if ($quoteRequest === null) {
            return false;
        }

        try {
            $response = $this->gateway->quote($quoteRequest);
        } catch (\Throwable $e) {
            $this->_logger->warning(
                'ShippoMagentoCarrier: gateway quote failed, omitting Shippo from checkout',
                ['error' => $e->getMessage()],
            );
            return false;
        }

        if ($response->options === []) {
            return false;
        }

        /** @var Result $result */
        $result = $this->rateResultFactory->create();
        foreach ($response->options as $option) {
            $result->append($this->buildRateMethod($option));
        }
        return $result;
    }

    /**
     * Build a {@see QuoteRequest} from the Magento rate request, resolving
     * merchant context via a mutable DataObject event. If no observer answers,
     * returns null and the caller falls through (carrier omitted from rates).
     */
    private function buildQuoteRequest(RateRequest $request): ?QuoteRequest
    {
        $context = new DataObject([
            'merchant_id' => null,
            'origin' => null,
            'items' => $request->getAllItems() ?? [],
            'rate_request' => $request,
        ]);

        $this->eventManager->dispatch(
            self::EVENT_RESOLVE_RATE_CONTEXT,
            ['context' => $context],
        );

        $merchantId = (int)($context->getData('merchant_id') ?? 0);
        if ($merchantId <= 0) {
            return null;
        }

        $origin = $context->getData('origin');
        if (!$origin instanceof ContactAddress) {
            return null;
        }

        $country = (string)($request->getDestCountryId() ?? '');
        $postcode = (string)($request->getDestPostcode() ?? '');
        $destination = new ContactAddress(
            name: '',
            phone: '',
            email: null,
            country: $country,
            subdivision: (string)($request->getDestRegionCode() ?? ''),
            city: (string)($request->getDestCity() ?? ''),
            district: null,
            street: (string)($request->getDestStreet() ?? ''),
            building: null,
            floor: null,
            apartment: null,
            postcode: $postcode !== '' ? $postcode : null,
            latitude: null,
            longitude: null,
            instructions: null,
        );

        $weightKg = (float)($request->getPackageWeight() ?? 0.0);
        $valueGel = (float)($request->getPackageValue() ?? 0.0);
        $parcel = new ParcelSpec(
            weightGrams: (int)round($weightKg * 1000.0),
            lengthMm: 0,
            widthMm: 0,
            heightMm: 0,
            declaredValueCents: (int)round($valueGel * 100.0),
        );

        return new QuoteRequest(
            merchantId: $merchantId,
            origin: $origin,
            destination: $destination,
            parcel: $parcel,
        );
    }

    /**
     * Map a single {@see RateOption} to a Magento rate {@see Method} row.
     * The Shippo `rate_object_id` is stamped on the Method so the
     * post-checkout label-purchase pipeline can reuse the exact rate the
     * customer saw at checkout.
     */
    private function buildRateMethod(RateOption $option): Method
    {
        /** @var Method $method */
        $method = $this->rateMethodFactory->create();
        $priceGel = bcdiv((string)$option->priceCents, '100', 2);

        $method->setCarrier(self::CARRIER_CODE);
        $method->setCarrierTitle((string)$this->getConfigData('title'));
        $method->setMethod($option->methodCode);
        $method->setMethodTitle($option->serviceLevel);
        $method->setPrice($priceGel);
        $method->setCost($priceGel);

        // Encode rate_object_id in method_description — the only text column in
        // quote_shipping_rate that survives the DB round-trip via Rate::importShippingRate().
        // CopyRateObjectIdToOrderPlugin reads this JSON at order placement time.
        $metadata = $option->adapterMetadata ?? [];
        $rateObjectId = is_string($metadata['rate_object_id'] ?? null) ? (string)$metadata['rate_object_id'] : '';
        if ($rateObjectId !== '') {
            $carrierToken = is_string($metadata['carrier_token'] ?? null) ? (string)$metadata['carrier_token'] : '';
            $encoded = json_encode(['rate_object_id' => $rateObjectId, 'carrier_token' => $carrierToken]);
            if ($encoded !== false) {
                $method->setMethodDescription($encoded);
            }
        }

        return $method;
    }
}
