<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Test\Unit\Plugin\Sales;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\ShippingShippo\Plugin\Sales\CopyRateObjectIdToOrderPlugin;

/**
 * @covers \Shubo\ShippingShippo\Plugin\Sales\CopyRateObjectIdToOrderPlugin
 */
class CopyRateObjectIdToOrderPluginTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orderRepository;
    private LoggerInterface&MockObject $logger;
    private CopyRateObjectIdToOrderPlugin $plugin;
    private QuoteManagement&MockObject $subject;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->plugin = new CopyRateObjectIdToOrderPlugin($this->orderRepository, $this->logger);
        $this->subject = $this->createMock(QuoteManagement::class);
    }

    public function testAfterSubmitCopiesRateObjectIdToOrder(): void
    {
        $shippingMethod = 'shippo_USPS_PRIORITY';
        $rate = $this->makeRate($shippingMethod, (string)json_encode(['rate_object_id' => 'r_xyz']));
        $quote = $this->makeQuoteWithRates([$rate]);

        $order = $this->createMock(Order::class);
        $order->method('getData')
            ->with('shipping_method')
            ->willReturn($shippingMethod);

        // The plugin must persist the extracted rate_object_id back to the order.
        $order->expects(self::once())
            ->method('setData')
            ->with('shippo_rate_object_id', 'r_xyz')
            ->willReturnSelf();

        $this->orderRepository->expects(self::once())
            ->method('save')
            ->with($order)
            ->willReturn($order);

        $this->logger->expects(self::never())->method('warning');
        $this->logger->expects(self::never())->method('error');

        $returned = $this->plugin->afterSubmit($this->subject, $order, $quote);

        self::assertSame($order, $returned);
    }

    public function testAfterSubmitIsNoOpForNonShippoMethod(): void
    {
        $quote = $this->createMock(Quote::class);
        // Quote must NOT be queried at all when shipping_method isn't shippo_*.
        $quote->expects(self::never())->method('getShippingAddress');

        $order = $this->createMock(Order::class);
        $order->method('getData')
            ->with('shipping_method')
            ->willReturn('flatrate_flatrate');

        $order->expects(self::never())->method('setData');
        $this->orderRepository->expects(self::never())->method('save');
        $this->logger->expects(self::never())->method('warning');
        $this->logger->expects(self::never())->method('error');

        $returned = $this->plugin->afterSubmit($this->subject, $order, $quote);

        self::assertSame($order, $returned);
    }

    public function testAfterSubmitLogsWarningWhenNoRateFound(): void
    {
        $shippingMethod = 'shippo_USPS_PRIORITY';
        // Rate matches the code but has empty method_description → extractor returns null.
        $rate = $this->makeRate($shippingMethod, '');
        $quote = $this->makeQuoteWithRates([$rate]);

        $order = $this->createMock(Order::class);
        $order->method('getData')
            ->with('shipping_method')
            ->willReturn($shippingMethod);
        $order->method('getIncrementId')->willReturn('100000042');

        $order->expects(self::never())->method('setData');
        $this->orderRepository->expects(self::never())->method('save');

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                'CopyRateObjectIdToOrderPlugin: no rate_object_id found for Shippo order',
                self::callback(static function (array $ctx) use ($shippingMethod): bool {
                    return ($ctx['order_increment_id'] ?? null) === '100000042'
                        && ($ctx['shipping_method'] ?? null) === $shippingMethod;
                }),
            );

        $returned = $this->plugin->afterSubmit($this->subject, $order, $quote);

        self::assertSame($order, $returned);
    }

    /**
     * @param list<Rate&MockObject> $rates
     */
    private function makeQuoteWithRates(array $rates): Quote&MockObject
    {
        $address = $this->createMock(Address::class);
        $address->method('getAllShippingRates')->willReturn($rates);

        $quote = $this->createMock(Quote::class);
        $quote->method('getShippingAddress')->willReturn($address);

        return $quote;
    }

    private function makeRate(string $code, string $methodDescription): Rate&MockObject
    {
        // Rate::getMethodDescription is a magic @method on AbstractModel — declared
        // via docblock but not implemented as a concrete method. PHPUnit cannot
        // auto-stub it via createMock(), so we declare it explicitly on the mock
        // alongside the real getData() method we also override.
        $rate = $this->getMockBuilder(Rate::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->addMethods(['getMethodDescription'])
            ->getMock();
        $rate->method('getData')
            ->with('code')
            ->willReturn($code);
        $rate->method('getMethodDescription')->willReturn($methodDescription);
        return $rate;
    }
}
