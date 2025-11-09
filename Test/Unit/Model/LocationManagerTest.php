<?php

declare(strict_types=1);

namespace Madar\StockAvailability\Test\Unit\Model;

use Madar\StockAvailability\Helper\StockHelper;
use Madar\StockAvailability\Logger\Location as LocationLogger;
use Madar\StockAvailability\Model\LocationManager;
use Madar\StockAvailability\Model\Session;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class LocationManagerTest extends TestCase
{
    /**
     * @param Session&MockObject $session
     * @param ScopeConfigInterface&MockObject $scopeConfig
     */
    private function createLocationManager(
        Session $session,
        ScopeConfigInterface $scopeConfig,
        ?LocationLogger $logger = null
    ): LocationManager
    {
        $customerSession = $this->createMock(CustomerSession::class);
        $customerSession->method('isLoggedIn')->willReturn(false);

        $checkoutSession = $this->createMock(CheckoutSession::class);
        $checkoutSession->method('getQuote')->willReturn(null);

        if ($logger === null) {
            $logger = $this->createMock(LocationLogger::class);
            $logger->method('error')->willReturn(null);
            $logger->method('info')->willReturn(null);
            $logger->method('debug')->willReturn(null);
        }

        return new LocationManager(
            $session,
            $customerSession,
            $checkoutSession,
            $this->createMock(CartRepositoryInterface::class),
            $this->createMock(AddressRepositoryInterface::class),
            $this->createMock(AddressInterfaceFactory::class),
            $this->createMock(StockHelper::class),
            $this->createMock(RegionFactory::class),
            $scopeConfig,
            $logger
        );
    }

    public function testPersistLocationAppliesDefaultCountry(): void
    {
        $sessionData = [];
        $session = $this->createMock(Session::class);
        $session->method('setData')->willReturnCallback(function ($key, $value = null) use (&$sessionData, $session) {
            $sessionData[$key] = $value;

            return $session;
        });
        $session->method('getData')->willReturnCallback(function ($key = '') use (&$sessionData) {
            return $sessionData[$key] ?? null;
        });

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['general/country/default', ScopeInterface::SCOPE_STORE, 'AE'],
            ['general/country/allow', ScopeInterface::SCOPE_STORE, 'AE,SA'],
        ]);

        $locationManager = $this->createLocationManager($session, $scopeConfig);

        $payload = [
            'address' => [
                'firstname' => 'John',
                'street' => ['Line 1'],
            ],
        ];

        $result = $locationManager->persistLocation($payload);

        $this->assertSame('AE', $result['shipping_address']['country_id']);
        $this->assertSame('AE', $sessionData['location_shipping_address']['country_id']);
        $this->assertSame('AE', $result['prefill']['shipping_address']['country_id']);
    }

    public function testCheckoutPrefillAddsDefaultCountryWhenMissing(): void
    {
        $sessionData = [
            'location_shipping_address' => [
                'firstname' => 'Jane',
                'street' => ['Line 1'],
                'country_id' => '',
            ],
        ];

        $session = $this->createMock(Session::class);
        $session->method('setData')->willReturnCallback(function ($key, $value = null) use (&$sessionData, $session) {
            $sessionData[$key] = $value;

            return $session;
        });
        $session->method('getData')->willReturnCallback(function ($key = '') use (&$sessionData) {
            return $sessionData[$key] ?? null;
        });

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['general/country/default', ScopeInterface::SCOPE_STORE, 'AE'],
            ['general/country/allow', ScopeInterface::SCOPE_STORE, 'AE,SA'],
        ]);

        $locationManager = $this->createLocationManager($session, $scopeConfig);

        $prefill = $locationManager->getCheckoutPrefillData();

        $this->assertArrayHasKey('shipping_address', $prefill);
        $this->assertSame('AE', $prefill['shipping_address']['country_id']);
    }

    public function testDisallowedCountryFallsBackToAllowedListAndLogs(): void
    {
        $sessionData = [];
        $session = $this->createMock(Session::class);
        $session->method('setData')->willReturnCallback(function ($key, $value = null) use (&$sessionData, $session) {
            $sessionData[$key] = $value;

            return $session;
        });
        $session->method('getData')->willReturnCallback(function ($key = '') use (&$sessionData) {
            return $sessionData[$key] ?? null;
        });

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['general/country/default', ScopeInterface::SCOPE_STORE, 'AE'],
            ['general/country/allow', ScopeInterface::SCOPE_STORE, 'AE,SA'],
        ]);

        $debugEntries = [];
        $logger = $this->createMock(LocationLogger::class);
        $logger->method('error')->willReturn(null);
        $logger->method('info')->willReturn(null);
        $logger->method('debug')->willReturnCallback(function ($message, array $context = []) use (&$debugEntries) {
            $debugEntries[] = ['message' => $message, 'context' => $context];
        });

        $locationManager = $this->createLocationManager($session, $scopeConfig, $logger);

        $payload = [
            'address' => [
                'firstname' => 'John',
                'street' => ['Line 1'],
                'country_id' => 'US',
            ],
        ];

        $result = $locationManager->persistLocation($payload);

        $this->assertSame('AE', $result['shipping_address']['country_id']);
        $this->assertSame('AE', $result['prefill']['shipping_address']['country_id']);

        $fallbackLog = array_filter($debugEntries, static function (array $entry) {
            return ($entry['context']['fallback_country_id'] ?? null) === 'AE'
                || ($entry['context']['resolved_country_id'] ?? null) === 'AE';
        });

        $this->assertNotEmpty($fallbackLog, 'Expected fallback logging to be recorded.');
    }

    public function testCheckoutPrefillSkipsCountryWhenNoFallbackAvailable(): void
    {
        $sessionData = [
            'location_shipping_address' => [
                'firstname' => 'Jane',
                'street' => ['Line 1'],
                'country_id' => '',
            ],
        ];

        $session = $this->createMock(Session::class);
        $session->method('setData')->willReturnCallback(function ($key, $value = null) use (&$sessionData, $session) {
            $sessionData[$key] = $value;

            return $session;
        });
        $session->method('getData')->willReturnCallback(function ($key = '') use (&$sessionData) {
            return $sessionData[$key] ?? null;
        });

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnMap([
            ['general/country/default', ScopeInterface::SCOPE_STORE, ''],
            ['general/country/allow', ScopeInterface::SCOPE_STORE, ''],
        ]);

        $debugEntries = [];
        $logger = $this->createMock(LocationLogger::class);
        $logger->method('error')->willReturn(null);
        $logger->method('info')->willReturn(null);
        $logger->method('debug')->willReturnCallback(function ($message, array $context = []) use (&$debugEntries) {
            $debugEntries[] = ['message' => $message, 'context' => $context];
        });

        $locationManager = $this->createLocationManager($session, $scopeConfig, $logger);

        $prefill = $locationManager->getCheckoutPrefillData();

        $this->assertArrayHasKey('shipping_address', $prefill);
        $this->assertArrayNotHasKey('country_id', $prefill['shipping_address']);

        $noFallbackLog = array_filter($debugEntries, static function (array $entry) {
            return array_key_exists('resolved_country_id', $entry['context'])
                && $entry['context']['resolved_country_id'] === null;
        });

        $this->assertNotEmpty($noFallbackLog, 'Expected logging when no fallback country is available.');
    }
}
