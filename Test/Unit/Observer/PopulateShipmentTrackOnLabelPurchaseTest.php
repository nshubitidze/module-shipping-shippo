<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Shubo\ShippingShippo\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\ShipmentTrackRepositoryInterface;
use Magento\Sales\Model\Order\Shipment\Track;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Track\Collection as TrackCollection;
use Magento\Sales\Model\ResourceModel\Order\Shipment\Track\CollectionFactory as TrackCollectionFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shubo\ShippingCore\Api\Data\ShipmentInterface;
use Shubo\ShippingShippo\Api\Data\ShippoTransactionInterface;
use Shubo\ShippingShippo\Api\ShippoTransactionRepositoryInterface;
use Shubo\ShippingShippo\Observer\PopulateShipmentTrackOnLabelPurchase;

/**
 * @covers \Shubo\ShippingShippo\Observer\PopulateShipmentTrackOnLabelPurchase
 */
class PopulateShipmentTrackOnLabelPurchaseTest extends TestCase
{
    private ShippoTransactionRepositoryInterface&MockObject $txRepo;
    private TrackFactory&MockObject $trackFactory;
    private ShipmentTrackRepositoryInterface&MockObject $trackRepository;
    private TrackCollectionFactory&MockObject $trackCollectionFactory;
    private LoggerInterface&MockObject $logger;
    private PopulateShipmentTrackOnLabelPurchase $observer;

    protected function setUp(): void
    {
        $this->txRepo = $this->createMock(ShippoTransactionRepositoryInterface::class);
        $this->trackFactory = $this->createMock(TrackFactory::class);
        $this->trackRepository = $this->createMock(ShipmentTrackRepositoryInterface::class);
        $this->trackCollectionFactory = $this->createMock(TrackCollectionFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->observer = new PopulateShipmentTrackOnLabelPurchase(
            $this->txRepo,
            $this->trackFactory,
            $this->trackRepository,
            $this->trackCollectionFactory,
            $this->logger,
        );
    }

    public function testReturnsEarlyWhenShipmentMissing(): void
    {
        $this->trackFactory->expects(self::never())->method('create');
        $this->trackRepository->expects(self::never())->method('save');

        $this->observer->execute($this->buildObserver(['shipment' => null]));
    }

    public function testReturnsEarlyForNonShippoCarrier(): void
    {
        $shipment = $this->createMock(ShipmentInterface::class);
        $shipment->method('getCarrierCode')->willReturn('shuboflat');

        $this->trackFactory->expects(self::never())->method('create');
        $this->trackRepository->expects(self::never())->method('save');

        $this->observer->execute($this->buildObserver(['shipment' => $shipment]));
    }

    public function testReturnsEarlyAndWarnsWhenNoMagentoShipmentId(): void
    {
        $shipment = $this->createMock(ShipmentInterface::class);
        $shipment->method('getCarrierCode')->willReturn('shippo');
        $shipment->method('getMagentoShipmentId')->willReturn(null);
        $shipment->method('getShipmentId')->willReturn(42);

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                'PopulateShipmentTrackOnLabelPurchase: no Magento shipment id, skipping track creation',
                ['shubo_shipment_id' => 42],
            );
        $this->trackFactory->expects(self::never())->method('create');
        $this->trackRepository->expects(self::never())->method('save');

        $this->observer->execute($this->buildObserver(['shipment' => $shipment]));
    }

    public function testReturnsEarlyWhenTrackingNumberMissing(): void
    {
        $shipment = $this->createMock(ShipmentInterface::class);
        $shipment->method('getCarrierCode')->willReturn('shippo');
        $shipment->method('getMagentoShipmentId')->willReturn(7);
        $shipment->method('getCarrierTrackingId')->willReturn(null);

        $this->trackFactory->expects(self::never())->method('create');
        $this->trackRepository->expects(self::never())->method('save');

        $this->observer->execute($this->buildObserver(['shipment' => $shipment]));
    }

    public function testReturnsEarlyWhenTrackAlreadyExists(): void
    {
        $shipment = $this->buildShippoShipmentMock(magentoShipmentId: 7, trackingNumber: '1Z999');
        $this->primeCollectionWithSize(1);

        // No DB write attempted when an existing track already covers this number.
        $this->trackFactory->expects(self::never())->method('create');
        $this->trackRepository->expects(self::never())->method('save');

        $this->observer->execute($this->buildObserver(['shipment' => $shipment]));
    }

    public function testCreatesTrackWithUspsDeepLink(): void
    {
        $shipment = $this->buildShippoShipmentMock(magentoShipmentId: 7, trackingNumber: '9400111200');
        $this->primeCollectionWithSize(0);

        $tx = $this->createMock(ShippoTransactionInterface::class);
        $tx->method('getCarrier')->willReturn('usps');
        $this->txRepo->expects(self::once())
            ->method('getByTrackingNumber')
            ->with('9400111200')
            ->willReturn($tx);

        $track = $this->createMock(Track::class);
        $this->trackFactory->expects(self::once())->method('create')->willReturn($track);
        $track->expects(self::once())->method('setParentId')->with(7)->willReturnSelf();
        $track->expects(self::once())->method('setCarrierCode')->with('shippo')->willReturnSelf();
        $track->expects(self::once())->method('setTitle')->with('USPS via Shippo')->willReturnSelf();
        $track->expects(self::once())->method('setTrackNumber')->with('9400111200')->willReturnSelf();
        $track->expects(self::once())
            ->method('setDescription')
            ->with('https://tools.usps.com/go/TrackConfirmAction?tLabels=9400111200')
            ->willReturnSelf();

        $this->trackRepository->expects(self::once())->method('save')->with($track);
        $this->logger->expects(self::once())
            ->method('info')
            ->with(
                'PopulateShipmentTrackOnLabelPurchase: track row created',
                self::callback(static function (array $ctx): bool {
                    return ($ctx['magento_shipment_id'] ?? null) === 7
                        && ($ctx['tracking_number'] ?? null) === '9400111200'
                        && ($ctx['carrier'] ?? null) === 'usps';
                }),
            );

        $this->observer->execute($this->buildObserver(['shipment' => $shipment]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function carrierUrlProvider(): iterable
    {
        yield 'ups' => ['ups', 'https://www.ups.com/track?tracknum=1Z999AA10123456784'];
        yield 'fedex' => ['fedex', 'https://www.fedex.com/fedextrack/?trknbr=794601234567'];
        yield 'dhl' => ['dhl', 'https://www.dhl.com/en/express/tracking.html?AWB=1234567890'];
        yield 'dhl_express' => ['dhl_express', 'https://www.dhl.com/en/express/tracking.html?AWB=1234567890'];
        yield 'unknown_carrier' => ['canpar', 'https://track.goshippo.com/track/canpar/CANPAR-1'];
    }

    /**
     * @dataProvider carrierUrlProvider
     */
    public function testBuildsCorrectUrlPerCarrier(string $carrier, string $expectedUrl): void
    {
        $tracking = match ($carrier) {
            'ups' => '1Z999AA10123456784',
            'fedex' => '794601234567',
            'dhl', 'dhl_express' => '1234567890',
            default => 'CANPAR-1',
        };

        $shipment = $this->buildShippoShipmentMock(magentoShipmentId: 99, trackingNumber: $tracking);
        $this->primeCollectionWithSize(0);

        $tx = $this->createMock(ShippoTransactionInterface::class);
        $tx->method('getCarrier')->willReturn($carrier);
        $this->txRepo->method('getByTrackingNumber')->willReturn($tx);

        $track = $this->createMock(Track::class);
        $this->trackFactory->method('create')->willReturn($track);
        $track->method('setParentId')->willReturnSelf();
        $track->method('setCarrierCode')->willReturnSelf();
        $track->method('setTitle')->willReturnSelf();
        $track->method('setTrackNumber')->willReturnSelf();
        $track->expects(self::once())->method('setDescription')->with($expectedUrl)->willReturnSelf();

        $this->trackRepository->expects(self::once())->method('save')->with($track);

        $this->observer->execute($this->buildObserver(['shipment' => $shipment]));
    }

    public function testFallsBackToShippoTitleWhenTransactionLookupFails(): void
    {
        $shipment = $this->buildShippoShipmentMock(magentoShipmentId: 12, trackingNumber: 'UNKNOWN-XYZ');
        $this->primeCollectionWithSize(0);

        $this->txRepo->expects(self::once())
            ->method('getByTrackingNumber')
            ->willThrowException(new NoSuchEntityException(__('not found')));

        $track = $this->createMock(Track::class);
        $this->trackFactory->method('create')->willReturn($track);
        $track->method('setParentId')->willReturnSelf();
        $track->method('setCarrierCode')->willReturnSelf();
        // No carrier known -> generic "Shippo" title and no description (URL omitted to
        // avoid emitting a broken Shippo tracker link with empty carrier path segment).
        $track->expects(self::once())->method('setTitle')->with('Shippo')->willReturnSelf();
        $track->method('setTrackNumber')->willReturnSelf();
        $track->expects(self::never())->method('setDescription');

        $this->trackRepository->expects(self::once())->method('save')->with($track);

        $this->observer->execute($this->buildObserver(['shipment' => $shipment]));
    }

    public function testSwallowsAndLogsRepositorySaveFailure(): void
    {
        $shipment = $this->buildShippoShipmentMock(magentoShipmentId: 12, trackingNumber: 'TRK-123');
        $this->primeCollectionWithSize(0);

        $tx = $this->createMock(ShippoTransactionInterface::class);
        $tx->method('getCarrier')->willReturn('usps');
        $this->txRepo->method('getByTrackingNumber')->willReturn($tx);

        $track = $this->createMock(Track::class);
        $this->trackFactory->method('create')->willReturn($track);
        $track->method('setParentId')->willReturnSelf();
        $track->method('setCarrierCode')->willReturnSelf();
        $track->method('setTitle')->willReturnSelf();
        $track->method('setTrackNumber')->willReturnSelf();
        $track->method('setDescription')->willReturnSelf();

        $this->trackRepository->expects(self::once())
            ->method('save')
            ->willThrowException(new \RuntimeException('db dead'));

        $this->logger->expects(self::once())
            ->method('error')
            ->with(
                'PopulateShipmentTrackOnLabelPurchase: failed to save track',
                self::callback(static function (array $ctx): bool {
                    return ($ctx['magento_shipment_id'] ?? null) === 12
                        && ($ctx['tracking_number'] ?? null) === 'TRK-123'
                        && ($ctx['error'] ?? null) === 'db dead';
                }),
            );

        // Critically, no exception escapes the observer — Magento's event manager
        // ignores observer return values, but a thrown exception would bubble up
        // to the orchestrator and re-fail an already-successful label purchase.
        $this->observer->execute($this->buildObserver(['shipment' => $shipment]));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function buildObserver(array $data): Observer
    {
        $event = new Event($data);
        $observer = new Observer();
        $observer->setEvent($event);
        return $observer;
    }

    private function buildShippoShipmentMock(int $magentoShipmentId, string $trackingNumber): ShipmentInterface&MockObject
    {
        $shipment = $this->createMock(ShipmentInterface::class);
        $shipment->method('getCarrierCode')->willReturn('shippo');
        $shipment->method('getMagentoShipmentId')->willReturn($magentoShipmentId);
        $shipment->method('getCarrierTrackingId')->willReturn($trackingNumber);
        return $shipment;
    }

    private function primeCollectionWithSize(int $size): void
    {
        $collection = $this->createMock(TrackCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getSize')->willReturn($size);
        $this->trackCollectionFactory->method('create')->willReturn($collection);
    }
}
