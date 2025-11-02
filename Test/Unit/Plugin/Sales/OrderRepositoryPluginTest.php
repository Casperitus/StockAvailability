<?php

namespace Madar\StockAvailability\Test\Unit\Plugin\Sales;

use Madar\StockAvailability\Model\LocationManager;
use Madar\StockAvailability\Plugin\Sales\OrderRepositoryPlugin;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrderRepositoryPluginTest extends TestCase
{
    public function testBeforeSaveDelegatesToLocationManager(): void
    {
        $locationManager = $this->createMock(LocationManager::class);
        $loggerMock = $this->createMock(LoggerInterface::class);
        $orderMock = $this->createMock(OrderInterface::class);

        $locationManager
            ->expects($this->once())
            ->method('applyToOrder')
            ->with($orderMock);

        $plugin = new OrderRepositoryPlugin($locationManager, $loggerMock);
        $plugin->beforeSave($this->createMock(OrderRepositoryInterface::class), $orderMock);
    }
}
