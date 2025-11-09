<?php

declare(strict_types=1);

namespace Hyva\Checkout\Model\Form {
    class EntityFormService
    {
    }
}

namespace Madar\StockAvailability\Test\Unit\Plugin\Hyva\Checkout;

use Hyva\Checkout\Model\Form\EntityFormService;
use Madar\StockAvailability\Model\LocationManager;
use Madar\StockAvailability\Plugin\Hyva\Checkout\EntityFormServicePlugin;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class EntityFormServicePluginTest extends TestCase
{
    /** @var LocationManager|MockObject */
    private $locationManager;

    private EntityFormServicePlugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->locationManager = $this->createMock(LocationManager::class);
        $this->plugin = new EntityFormServicePlugin($this->locationManager);
    }

    public function testAddsSyntheticAddressFromSessionPrefill(): void
    {
        $shippingPrefill = [
            'firstname' => 'John',
            'lastname' => 'Doe',
            'telephone' => '123456',
            'street' => ['Line 1', 'Line 2'],
            'city' => 'Dubai',
            'postcode' => '00000',
            'country_id' => 'AE',
            'region' => ['region' => 'Dubai'],
            'latitude' => '25.2048',
            'longitude' => '55.2708',
        ];

        $this->locationManager
            ->method('getCheckoutPrefillData')
            ->willReturn(['shipping_address' => $shippingPrefill]);

        $initialResult = [
            'entity' => [
                'custom_attributes' => ['existing_attribute' => 'value'],
            ],
            'addresses' => [
                ['id' => 'existing-address', 'entity_id' => 'existing-address'],
            ],
            'selectedAddressId' => null,
        ];

        $subject = $this->createMock(EntityFormService::class);

        $result = $this->plugin->afterGetFormData($subject, $initialResult, 'shipping-address-form');

        $this->assertArrayHasKey('addresses', $result);

        $addresses = $result['addresses'];
        $this->assertCount(2, $addresses);

        $sessionAddress = end($addresses);
        $this->assertSame(EntityFormServicePlugin::SESSION_ADDRESS_ID, $sessionAddress['id']);
        $this->assertSame(EntityFormServicePlugin::SESSION_ADDRESS_ID, $sessionAddress['entity_id']);
        $this->assertSame('25.2048', $sessionAddress['custom_attributes']['latitude']);
        $this->assertSame('55.2708', $sessionAddress['custom_attributes']['longitude']);

        $this->assertSame(EntityFormServicePlugin::SESSION_ADDRESS_ID, $result['selectedAddressId']);
    }
}

