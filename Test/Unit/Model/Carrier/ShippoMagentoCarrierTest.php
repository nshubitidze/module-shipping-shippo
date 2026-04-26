<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Test\Unit\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Rate\Result;
use Magento\Shipping\Model\Rate\ResultFactory;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\ShippingCore\Api\Data\Dto\ContactAddress;
use Shubo\ShippingCore\Api\Data\Dto\QuoteRequest;
use Shubo\ShippingCore\Api\Data\Dto\QuoteResponse;
use Shubo\ShippingCore\Api\Data\Dto\RateOption;
use Shubo\ShippingShippo\Model\Adapter\ShippoCarrierGateway;
use Shubo\ShippingShippo\Model\Carrier\ShippoMagentoCarrier;

/**
 * @covers \Shubo\ShippingShippo\Model\Carrier\ShippoMagentoCarrier
 */
class ShippoMagentoCarrierTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private ErrorFactory&MockObject $errorFactory;
    private LoggerInterface&MockObject $logger;
    private ResultFactory&MockObject $resultFactory;
    private MethodFactory&MockObject $methodFactory;
    private ShippoCarrierGateway&MockObject $gateway;
    private EventManagerInterface&MockObject $eventManager;
    private PriceCurrencyInterface&MockObject $priceCurrency;
    private ShippoMagentoCarrier $carrier;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->errorFactory = $this->createMock(ErrorFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->resultFactory = $this->createMock(ResultFactory::class);
        $this->methodFactory = $this->createMock(MethodFactory::class);
        $this->gateway = $this->createMock(ShippoCarrierGateway::class);
        $this->eventManager = $this->createMock(EventManagerInterface::class);
        $this->priceCurrency = $this->createMock(PriceCurrencyInterface::class);

        // Method::setPrice() rounds via PriceCurrency — passthrough so we can
        // assert the final price without dragging in the full pricing stack.
        $this->priceCurrency->method('round')
            ->willReturnCallback(static fn ($value): float => round((float)$value, 2));

        $this->carrier = new ShippoMagentoCarrier(
            $this->scopeConfig,
            $this->errorFactory,
            $this->logger,
            $this->resultFactory,
            $this->methodFactory,
            $this->gateway,
            $this->eventManager,
        );
    }

    public function testCollectRatesReturnsFalseWhenDisabled(): void
    {
        $this->scopeConfig->expects(self::once())
            ->method('isSetFlag')
            ->with('carriers/shippo/active')
            ->willReturn(false);

        // Disabled carrier must short-circuit before any other collaborator runs.
        $this->eventManager->expects(self::never())->method('dispatch');
        $this->gateway->expects(self::never())->method('quote');
        $this->resultFactory->expects(self::never())->method('create');

        self::assertFalse($this->carrier->collectRates(new RateRequest()));
    }

    public function testCollectRatesReturnsFalseWhenNoMerchantContext(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(true);

        // Observer never fires (or fires but writes nothing) → merchant_id stays 0.
        $this->eventManager->expects(self::once())
            ->method('dispatch')
            ->with(ShippoMagentoCarrier::EVENT_RESOLVE_RATE_CONTEXT, self::callback(
                static fn (array $args): bool => isset($args['context']) && $args['context'] instanceof DataObject,
            ));

        $this->gateway->expects(self::never())->method('quote');

        self::assertFalse($this->carrier->collectRates(new RateRequest()));
    }

    public function testCollectRatesReturnsFalseWhenGatewayThrows(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(true);
        $this->stubMerchantContext();

        $this->gateway->expects(self::once())
            ->method('quote')
            ->willThrowException(new \RuntimeException('shippo down'));

        // Failure must be logged but never rethrown — checkout cannot be blocked
        // by a transport error; Shippo simply drops out of the rate list.
        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                'ShippoMagentoCarrier: gateway quote failed, omitting Shippo from checkout',
                self::callback(
                    static fn (array $ctx): bool => ($ctx['error'] ?? null) === 'shippo down',
                ),
            );

        self::assertFalse($this->carrier->collectRates(new RateRequest()));
    }

    public function testCollectRatesBuildsSingleRateMethodWithRateObjectId(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(true);
        $this->scopeConfig->method('getValue')
            ->with('carriers/shippo/title')
            ->willReturn('Shippo');
        $this->stubMerchantContext();

        $this->gateway->expects(self::once())
            ->method('quote')
            ->willReturn(new QuoteResponse(
                options: [
                    new RateOption(
                        carrierCode: 'usps',
                        methodCode: 'usps_priority',
                        priceCents: 1500,
                        etaDays: 3,
                        serviceLevel: 'USPS Priority',
                        rationale: 'shippo-rate-r_abc',
                        adapterMetadata: ['rate_object_id' => 'r_abc', 'carrier_token' => 'USPS'],
                    ),
                ],
            ));

        $this->resultFactory->expects(self::once())
            ->method('create')
            ->willReturn($this->makeRealResult());
        $this->methodFactory->expects(self::once())
            ->method('create')
            ->willReturn($this->makeRealMethod());

        $result = $this->carrier->collectRates(new RateRequest());

        self::assertInstanceOf(Result::class, $result);
        $methods = $result->getAllRates();
        self::assertCount(1, $methods);
        $method = $methods[0];
        self::assertSame('shippo', $method->getCarrier());
        self::assertSame('Shippo', $method->getCarrierTitle());
        self::assertSame('usps_priority', $method->getMethod());
        self::assertSame('USPS Priority', $method->getMethodTitle());
        self::assertSame(15.0, $method->getPrice());

        $description = $method->getMethodDescription();
        self::assertIsString($description);
        $decoded = json_decode($description, true);
        self::assertIsArray($decoded);
        self::assertSame('r_abc', $decoded['rate_object_id']);
        self::assertSame('USPS', $decoded['carrier_token']);
    }

    public function testCollectRatesBuildsMultipleRateMethods(): void
    {
        $this->scopeConfig->method('isSetFlag')->willReturn(true);
        $this->scopeConfig->method('getValue')->willReturn('Shippo');
        $this->stubMerchantContext();

        $this->gateway->expects(self::once())
            ->method('quote')
            ->willReturn(new QuoteResponse(
                options: [
                    new RateOption(
                        carrierCode: 'usps',
                        methodCode: 'usps_priority',
                        priceCents: 1500,
                        etaDays: 3,
                        serviceLevel: 'USPS Priority',
                        rationale: 'shippo-rate-r_aaa',
                        adapterMetadata: ['rate_object_id' => 'r_aaa', 'carrier_token' => 'USPS'],
                    ),
                    new RateOption(
                        carrierCode: 'dhl_express',
                        methodCode: 'dhl_express_express',
                        priceCents: 2200,
                        etaDays: 2,
                        serviceLevel: 'DHL Express Worldwide',
                        rationale: 'shippo-rate-r_bbb',
                        adapterMetadata: ['rate_object_id' => 'r_bbb', 'carrier_token' => 'DHL'],
                    ),
                ],
            ));

        $this->resultFactory->expects(self::once())
            ->method('create')
            ->willReturn($this->makeRealResult());
        $this->methodFactory->expects(self::exactly(2))
            ->method('create')
            ->willReturnCallback(fn (): Method => $this->makeRealMethod());

        $result = $this->carrier->collectRates(new RateRequest());

        self::assertInstanceOf(Result::class, $result);
        $methods = $result->getAllRates();
        self::assertCount(2, $methods);
        self::assertSame('usps_priority', $methods[0]->getMethod());
        self::assertSame('dhl_express_express', $methods[1]->getMethod());

        $first = json_decode((string)$methods[0]->getMethodDescription(), true);
        self::assertIsArray($first);
        self::assertSame('r_aaa', $first['rate_object_id']);

        $second = json_decode((string)$methods[1]->getMethodDescription(), true);
        self::assertIsArray($second);
        self::assertSame('r_bbb', $second['rate_object_id']);
    }

    /**
     * Wire the event-manager mock to populate the rate context with a valid
     * merchant id and origin, mimicking what a real observer would do.
     */
    private function stubMerchantContext(): void
    {
        $this->eventManager->method('dispatch')
            ->willReturnCallback(function (string $event, array $args): void {
                if ($event !== ShippoMagentoCarrier::EVENT_RESOLVE_RATE_CONTEXT) {
                    return;
                }
                self::assertArrayHasKey('context', $args);
                $context = $args['context'];
                self::assertInstanceOf(DataObject::class, $context);
                $context->setData('merchant_id', 1);
                $context->setData('origin', $this->makeOriginAddress());
            });
    }

    private function makeOriginAddress(): ContactAddress
    {
        return new ContactAddress(
            name: 'Origin Co',
            phone: '+10000000000',
            email: null,
            country: 'US',
            subdivision: 'CA',
            city: 'San Francisco',
            district: null,
            street: '1 Market St',
            building: null,
            floor: null,
            apartment: null,
            postcode: '94107',
            latitude: null,
            longitude: null,
            instructions: null,
        );
    }

    private function makeRealResult(): Result
    {
        $storeManager = $this->createMock(StoreManagerInterface::class);
        return new Result($storeManager);
    }

    private function makeRealMethod(): Method
    {
        return new Method($this->priceCurrency);
    }
}
