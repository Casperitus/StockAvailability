<?php

namespace Madar\StockAvailability\Block;

use Magento\Framework\View\Element\Template;
use Madar\StockAvailability\Helper\StockHelper;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Session\SessionManagerInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Registry;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\GroupedProduct\Model\Product\Type\Grouped as GroupedType;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\App\ObjectManager; // Add this line


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

    public function __construct(
        Template\Context $context,
        StockHelper $stockHelper,
        Registry $registry,
        ScopeConfigInterface $scopeConfig,
        SessionManagerInterface $session,
        CustomerSession $customerSession,
        AddressRepositoryInterface $addressRepository,
        CustomerRepositoryInterface $customerRepository,
        FormKey $formKey = null, // Make the formKey optional
        array $data = []
    ) {
        $this->stockHelper = $stockHelper;
        $this->registry = $registry;
        $this->session = $session;
        $this->scopeConfig = $scopeConfig;
        $this->customerSession = $customerSession;
        $this->addressRepository = $addressRepository;
        $this->customerRepository = $customerRepository;
        $this->formKey = $formKey ?: ObjectManager::getInstance()->get(FormKey::class); // Use ObjectManager if null
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

    public function getProductSku()
    {
        $product = $this->registry->registry('current_product');
        return $product ? $product->getSku() : null;
    }

    public function getProductData()
    {
        $product = $this->registry->registry('current_product');
        $productData = [
            'sku' => $product ? $product->getSku() : null,
            'id' => $product ? $product->getId() : null,
            'is_global_shipping' => $product ? (bool) $product->getData('is_global_shipping') : false,
        ];

        if ($product) {
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
        return $_COOKIE['customer_latitude'] ?? null;
    }

    public function getCustomerLongitude()
    {
        return $_COOKIE['customer_longitude'] ?? null;
    }
    public function isCustomerLoggedIn()
    {
        return $this->customerSession->isLoggedIn();
    }

    /**
     * Retrieve the selected_source_code from our session storage
     * 
     * @return string|null
     */
    public function getSelectedSourceCode()
    {
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
                    // We handle multi-line streets here:
                    $street = $address->getStreet();
                    $streetLine = '';
                    if (is_array($street) && !empty($street)) {
                        // Join multiple lines with a comma + space
                        $streetLine = implode(', ', $street);
                    }

                    // We can build a more descriptive "details" like:
                    // "<StreetLine>, <City>, <Region>, <CountryId>"
                    // or keep it minimal
                    $city = $address->getCity() ?: '';
                    $regionText = $address->getRegion() ?: '';
                    $countryId = $address->getCountryId() ?: '';

                    // latitude/longitude custom attributes
                    $latitude = $address->getCustomAttribute('latitude')
                        ? $address->getCustomAttribute('latitude')->getValue()
                        : null;
                    $longitude = $address->getCustomAttribute('longitude')
                        ? $address->getCustomAttribute('longitude')->getValue()
                        : null;

                    $addresses[] = [
                        'id' => $address->getId(),
                        'details' => trim("$streetLine, $city"), // or add region/country if you want
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                        // Possibly also show if it's default shipping/billing
                        'is_default_shipping' => $address->isDefaultShipping(),
                        'is_default_billing' => $address->isDefaultBilling(),
                    ];
                }
            } catch (\Exception $e) {
                $this->_logger->error($e->getMessage());
            }
        }

        return $addresses;
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

        // Fetch sources data
        $sourcesData = $this->stockHelper->getAllSourcesWithHubs();

        return [
            'apiKey' => $this->getReferrerApiKey(),
            'isLoggedIn' => $this->isCustomerLoggedIn(),
            'latitude' => $this->getCustomerLatitude(),
            'longitude' => $this->getCustomerLongitude(),
            'savedAddresses' => $this->getCustomerAddresses(),
            'customerData' => $customerData,
            'sourcesData' => $sourcesData, // Include sources data
            'selected_source_code' =>  $this->getSelectedSourceCode(),
        ];
    }
}
