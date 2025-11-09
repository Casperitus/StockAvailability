<?php

namespace Madar\StockAvailability\Block;

use Madar\StockAvailability\Helper\StockHelper;
use Madar\StockAvailability\Logger\Location as LocationLogger;
use Madar\StockAvailability\Model\Session as LocationSession;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Directory\Model\ResourceModel\Region\CollectionFactory as RegionCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Registry;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Framework\View\Element\Template;
use Magento\GroupedProduct\Model\Product\Type\Grouped as GroupedType;

class GoogleMaps extends Template
{
    protected $stockHelper;
    protected $scopeConfig;
    protected $registry;
    protected $session;
    protected $customerSession;
    protected $addressRepository;
    protected $customerRepository;
    protected $formKey;
    protected LocationSession $locationSession;
    protected LocationLogger $locationLogger;
    protected RegionCollectionFactory $regionCollectionFactory;

    public function __construct(
        Template\Context $context,
        StockHelper $stockHelper,
        Registry $registry,
        ScopeConfigInterface $scopeConfig,
        SessionManagerInterface $session,
        LocationSession $locationSession,
        CustomerSession $customerSession,
        AddressRepositoryInterface $addressRepository,
        CustomerRepositoryInterface $customerRepository,
        LocationLogger $locationLogger,
        RegionCollectionFactory $regionCollectionFactory,
        FormKey $formKey = null,
        array $data = []
    ) {
        $this->stockHelper = $stockHelper;
        $this->registry = $registry;
        $this->session = $session;
        $this->locationSession = $locationSession;
        $this->scopeConfig = $scopeConfig;
        $this->customerSession = $customerSession;
        $this->addressRepository = $addressRepository;
        $this->customerRepository = $customerRepository;
        $this->locationLogger = $locationLogger;
        $this->regionCollectionFactory = $regionCollectionFactory;
        $this->formKey = $formKey ?: ObjectManager::getInstance()->get(FormKey::class);
        parent::__construct($context, $data);
    }

    /**
     * Get Referrer-Restricted API Key
     *
     * @return string
     */
    public function getReferrerApiKey()
    {
        return $this->scopeConfig->getValue(
            'madar_shiplus/general/referrer_api_key',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Get IP-Restricted API Key
     *
     * @return string
     */
    public function getIpRestrictedApiKey()
    {
        return $this->scopeConfig->getValue(
            'madar_shiplus/general/ip_restricted_api_key',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }
    /**
     * Get all sources with hubs for store availability
     *
     * @return array
     */
    public function getAllSourcesWithHubs()
    {
        return $this->stockHelper->getAllSourcesWithHubs();
    }

    /**
     * Get current product
     *
     * @return \Magento\Catalog\Model\Product|null
     */
    public function getProduct()
    {
        return $this->registry->registry('current_product');
    }
    public function getProductSku()
    {
        $product = $this->registry->registry('current_product');
        return $product ? $product->getSku() : null;
    }

    public function getProductData()
    {
        $product = $this->registry->registry('current_product');

        // Return empty data if no product (e.g., during registration)
        if (!$product) {
            return [
                'sku' => null,
                'id' => null,
                'is_global_shipping' => false,
            ];
        }

        $productData = [
            'sku' => $product->getSku(),
            'id' => $product->getId(),
            'is_global_shipping' => (bool) $product->getData('is_global_shipping'),
        ];

        if ($product->getTypeId() == ConfigurableType::TYPE_CODE) {
            $childProducts = $product->getTypeInstance()->getUsedProducts($product);
            $productData['child_skus'] = array_map(function ($childProduct) {
                return [
                    'sku' => $childProduct->getSku(),
                    'id' => $childProduct->getId(),
                ];
            }, $childProducts);
        } elseif ($product->getTypeId() == GroupedType::TYPE_CODE) {
            $childProducts = $product->getTypeInstance()->getAssociatedProducts($product);
            $productData['child_skus'] = array_map(function ($childProduct) {
                return [
                    'sku' => $childProduct->getSku(),
                    'id' => $childProduct->getId(),
                ];
            }, $childProducts);
        }

        return $productData;
    }

    public function getProductType()
    {
        $product = $this->registry->registry('current_product');
        return $product ? $product->getTypeId() : null;
    }

    public function getCustomerLatitude()
    {
        $cookieLatitude = $_COOKIE['customer_latitude'] ?? null;
        if ($cookieLatitude !== null && $cookieLatitude !== '') {
            return $cookieLatitude;
        }

        $sessionLatitude = $this->locationSession->getData('customer_latitude');
        if ($sessionLatitude) {
            return $sessionLatitude;
        }

        $fallback = $this->session->getData('customer_latitude');

        return $fallback ?: null;
    }

    public function getCustomerLongitude()
    {
        $cookieLongitude = $_COOKIE['customer_longitude'] ?? null;
        if ($cookieLongitude !== null && $cookieLongitude !== '') {
            return $cookieLongitude;
        }

        $sessionLongitude = $this->locationSession->getData('customer_longitude');
        if ($sessionLongitude) {
            return $sessionLongitude;
        }

        $fallback = $this->session->getData('customer_longitude');

        return $fallback ?: null;
    }

    public function isCustomerLoggedIn()
    {
        return $this->customerSession->isLoggedIn();
    }

    /**
     * Retrieve Magento regions for a given country.
     *
     * @param string $countryCode
     * @return array
     */
    public function getCountryRegions(string $countryCode = 'SA'): array
    {
        $collection = $this->regionCollectionFactory->create();
        $collection->addCountryFilter($countryCode);

        $regions = [];

        foreach ($collection as $region) {
            $regions[] = [
                'code' => $region->getCode(),
                'name' => $region->getName(),
            ];
        }

        usort($regions, static function (array $left, array $right): int {
            return strcmp($left['name'], $right['name']);
        });

        return $regions;
    }

    /**
     * Retrieve the selected_source_code from our session storage
     *
     * @return string|null
     */
    public function getSelectedSourceCode()
    {
        $sourceCode = $this->locationSession->getData('selected_source_code');
        if ($sourceCode) {
            return $sourceCode;
        }

        return $this->session->getData('selected_source_code') ?: null;
    }

    public function getCustomerAddresses()
    {
        $addresses = [];

        if ($this->isCustomerLoggedIn()) {
            $customerId = $this->customerSession->getCustomerId();

            try {
                $customer = $this->customerRepository->getById($customerId);
                $addressItems = $customer->getAddresses();

                foreach ($addressItems as $address) {
                    $streetLines = $this->sanitizeStreetLines($address->getStreet());
                    $district = $this->extractDistrict($streetLines);
                    $city = $address->getCity() ?: '';
                    $regionData = $this->mapRegionData($address);
                    $countryId = $address->getCountryId() ?: '';
                    $postcode = $address->getPostcode() ?: '';

                    $detailsParts = array_filter([
                        $streetLines[0] ?? '',
                        $district,
                        $city,
                        $regionData['region'] ?? '',
                    ]);

                    $latitude = $address->getCustomAttribute('latitude')
                        ? $address->getCustomAttribute('latitude')->getValue()
                        : null;
                    $longitude = $address->getCustomAttribute('longitude')
                        ? $address->getCustomAttribute('longitude')->getValue()
                        : null;

                    $addresses[] = array_filter([
                        'id' => $address->getId(),
                        'details' => implode(', ', $detailsParts),
                        'street' => $streetLines,
                        'district' => $district,
                        'city' => $city,
                        'postcode' => $postcode,
                        'country_id' => $countryId,
                        'region' => !empty($regionData) ? $regionData : null,
                        'region_id' => $regionData['region_id'] ?? null,
                        'region_code' => $regionData['region_code'] ?? null,
                        'firstname' => $address->getFirstname(),
                        'lastname' => $address->getLastname(),
                        'telephone' => $address->getTelephone(),
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        'is_default_shipping' => $address->isDefaultShipping(),
                        'is_default_billing' => $address->isDefaultBilling(),
                    ], static function ($value) {
                        if (is_array($value)) {
                            return !empty($value);
                        }

                        return $value !== null && $value !== '';
                    });
                }
            } catch (\Exception $e) {
                $this->_logger->error($e->getMessage());
            }
        }

        return $addresses;
    }

    public function getDefaultShippingAddressCoordinates()
    {
        if ($this->isCustomerLoggedIn()) {
            $customerId = $this->customerSession->getCustomerId();
            try {
                $customer = $this->customerRepository->getById($customerId);
                $addresses = $customer->getAddresses();

                foreach ($addresses as $address) {
                    if ($address->isDefaultShipping()) {
                        $latitude = $address->getCustomAttribute('latitude')
                            ? $address->getCustomAttribute('latitude')->getValue()
                            : null;
                        $longitude = $address->getCustomAttribute('longitude')
                            ? $address->getCustomAttribute('longitude')->getValue()
                            : null;

                        if ($latitude && $longitude) {
                            return [
                                'latitude' => $latitude,
                                'longitude' => $longitude,
                                'address_id' => $address->getId()
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                $this->_logger->error($e->getMessage());
            }
        }
        return null;
    }


    private function sanitizeStreetLines($street): array
    {
        if (is_array($street)) {
            $lines = $street;
        } elseif ($street) {
            $lines = [$street];
        } else {
            $lines = [];
        }

        $lines = array_map(static function ($line) {
            return is_string($line) ? trim($line) : $line;
        }, $lines);

        return array_values(array_filter($lines, static function ($line) {
            return $line !== null && $line !== '';
        }));
    }

    private function extractDistrict(array $streetLines): ?string
    {
        return $streetLines[1] ?? null;
    }

    private function mapRegionData(AddressInterface $address): array
    {
        $region = [];

        if ($address->getRegion()) {
            $region['region'] = $address->getRegion();
        }
        if ($address->getRegionId()) {
            $region['region_id'] = (int)$address->getRegionId();
        }
        if ($address->getRegionCode()) {
            $region['region_code'] = $address->getRegionCode();
        }

        return $region;
    }

    public function geocodeAddress($address)
    {
        $geocodeUrl = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($address) . "&key=" . $this->getReferrerApiKey();
        $response = file_get_contents($geocodeUrl);
        $data = json_decode($response);

        if ($data && $data->status === 'OK') {
            $location = $data->results[0]->geometry->location;
            return [
                'latitude' => $location->lat,
                'longitude' => $location->lng
            ];
        }

        return null;
    }

    public function prepareHyvaData()
    {
        $customerData = [];
        if ($this->isCustomerLoggedIn()) {
            $customer = $this->customerSession->getCustomer();
            $customerData = [
                'firstname' => $customer->getFirstname(),
                'lastname' => $customer->getLastname(),
                'telephone' => $customer->getPrimaryBillingAddress() ? $customer->getPrimaryBillingAddress()->getTelephone() : null,
            ];
        }

        $sourcesData = $this->stockHelper->getAllSourcesWithHubs();

        $defaultShippingCoords = $this->getDefaultShippingAddressCoordinates();

        $selectedSourceCode = $this->getSelectedSourceCode();
        $shouldAutoSelect = $defaultShippingCoords && !$selectedSourceCode;

        $latitude = $this->getCustomerLatitude();
        $longitude = $this->getCustomerLongitude();
        $defaultLatitude = $latitude !== null ? (float)$latitude : 24.7136;
        $defaultLongitude = $longitude !== null ? (float)$longitude : 46.6753;

        $selectedBranchName = $this->locationSession->getData('selected_branch_name')
            ?: $this->session->getData('selected_branch_name');
        $selectedBranchPhone = $this->locationSession->getData('selected_branch_phone')
            ?: $this->session->getData('selected_branch_phone');

        $hyvaData = [
            'apiKey' => $this->getReferrerApiKey(),
            'isLoggedIn' => $this->isCustomerLoggedIn(),
            'latitude' => $latitude !== null ? (float)$latitude : $defaultLatitude,
            'longitude' => $longitude !== null ? (float)$longitude : $defaultLongitude,
            'defaultLatitude' => $defaultLatitude,
            'defaultLongitude' => $defaultLongitude,
            'savedAddresses' => $this->getCustomerAddresses(),
            'customerData' => $customerData,
            'sourcesData' => $sourcesData,
            'selected_source_code' => $selectedSourceCode,
            'selected_branch_name' => $selectedBranchName,
            'selected_branch_phone' => $selectedBranchPhone,
            'defaultShippingAddress' => $defaultShippingCoords,
            'shouldAutoSelectAddress' => $shouldAutoSelect,
            'hasStoredLocation' => $latitude !== null && $longitude !== null,
        ];

        $sanitizedLog = $hyvaData;
        $sanitizedLog['apiKey'] = '***';
        $this->locationLogger->debug('Prepared Hyvä map data', ['data' => $sanitizedLog]);

        return $hyvaData;
    }
}
