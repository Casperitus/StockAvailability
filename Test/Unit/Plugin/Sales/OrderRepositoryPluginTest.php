<?php

namespace Madar\StockAvailability\Test\Unit\Plugin\Sales;

use Madar\StockAvailability\Helper\StockHelper;
use Madar\StockAvailability\Model\Session;
use Madar\StockAvailability\Plugin\Sales\OrderRepositoryPlugin;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrderRepositoryPluginTest extends TestCase
{
    public function testBeforeSaveUpdatesCoordinatesAndBranchWhenShippingAddressChanged(): void
    {
        $sessionData = [
            'selected_source_code' => 'OLD_BRANCH',
            'selected_branch_name' => 'Old Branch',
            'selected_branch_phone' => '123456789',
            'customer_latitude' => '24.7136',
            'customer_longitude' => '46.6753',
        ];

        $sessionMock = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData', 'setData'])
            ->getMock();

        $sessionMock->method('getData')->willReturnCallback(
            function (string $key) use (&$sessionData) {
                return $sessionData[$key] ?? null;
            }
        );

        $sessionMock->method('setData')->willReturnCallback(
            function (string $key, $value) use (&$sessionData) {
                $sessionData[$key] = $value;
                return null;
            }
        );

        $loggerMock = $this->createMock(LoggerInterface::class);

        $stockHelperMock = $this->createMock(StockHelper::class);
        $stockHelperMock->expects($this->once())
            ->method('findNearestSourceCode')
            ->with($this->equalTo(25.2048), $this->equalTo(55.2708))
            ->willReturn('NEW_BRANCH');

        $shippingAddress = new class(['latitude' => '25.2048', 'longitude' => '55.2708']) {
            private array $data;

            public function __construct(array $data = [])
            {
                $this->data = $data;
            }

            public function getData($key = '')
            {
                if ($key === '') {
                    return $this->data;
                }

                return $this->data[$key] ?? null;
            }

            public function setData($key, $value = null)
            {
                if (is_array($key)) {
                    foreach ($key as $field => $fieldValue) {
                        $this->data[$field] = $fieldValue;
                    }

                    return $this;
                }

                $this->data[$key] = $value;

                return $this;
            }
        };

        $orderData = [];
        $orderMock = $this->createMock(OrderInterface::class);
        $orderMock->method('getShippingAddress')->willReturn($shippingAddress);
        $orderMock->method('setData')->willReturnCallback(
            function (string $key, $value) use (&$orderData, $orderMock) {
                $orderData[$key] = $value;

                return $orderMock;
            }
        );

        $plugin = new OrderRepositoryPlugin($sessionMock, $loggerMock, $stockHelperMock);
        $plugin->beforeSave($this->createMock(OrderRepositoryInterface::class), $orderMock);

        $this->assertSame('NEW_BRANCH', $sessionData['selected_source_code']);
        $this->assertNull($sessionData['selected_branch_name']);
        $this->assertNull($sessionData['selected_branch_phone']);
        $this->assertSame('25.2048', (string)$sessionData['customer_latitude']);
        $this->assertSame('55.2708', (string)$sessionData['customer_longitude']);

        $this->assertSame('NEW_BRANCH', $orderData['delivery_source_code']);
        $this->assertArrayHasKey('delivery_branch_name', $orderData);
        $this->assertNull($orderData['delivery_branch_name']);
        $this->assertArrayHasKey('delivery_branch_phone', $orderData);
        $this->assertNull($orderData['delivery_branch_phone']);

        $this->assertSame('NEW_BRANCH', $shippingAddress->getData('delivery_source_code'));
        $this->assertNull($shippingAddress->getData('delivery_branch_name'));
        $this->assertNull($shippingAddress->getData('delivery_branch_phone'));
        $this->assertSame('25.2048', $shippingAddress->getData('latitude'));
        $this->assertSame('55.2708', $shippingAddress->getData('longitude'));
    }
}
