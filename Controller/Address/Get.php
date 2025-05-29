<?php

namespace Madar\StockAvailability\Controller\Address;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\HTTP\Client\Curl;
use Psr\Log\LoggerInterface;

class Get extends Action
{
    /**
     * @var JsonFactory
     */
    protected $jsonFactory;

    /**
     * @var CustomerSession
     */
    protected $customerSession;

    /**
     * @var AddressRepositoryInterface
     */
    protected $addressRepository;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(
        Context $context,
        JsonFactory $jsonFactory,
        CustomerSession $customerSession,
        AddressRepositoryInterface $addressRepository,
        ScopeConfigInterface $scopeConfig,
        Curl $curl,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->jsonFactory       = $jsonFactory;
        $this->customerSession   = $customerSession;
        $this->addressRepository = $addressRepository;
        $this->scopeConfig       = $scopeConfig;
        $this->curl              = $curl;
        $this->logger            = $logger;
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        try {
            if (!$this->customerSession->isLoggedIn()) {
                throw new LocalizedException(__('Customer not logged in.'));
            }

            $customerId = $this->customerSession->getCustomerId();
            // Optionally, you may pass an address_id via request. If not provided, use the default shipping address.
            $addressId = $this->getRequest()->getParam('address_id');

            if ($addressId) {
                $address = $this->addressRepository->getById($addressId);
                if ($address->getCustomerId() != $customerId) {
                    throw new LocalizedException(__('The address does not belong to the logged in customer.'));
                }
            } else {
                // Use the default shipping address if available; otherwise, take the first address.
                $customer = $this->customerSession->getCustomer();
                $addresses = $customer->getAddresses();
                if (empty($addresses)) {
                    throw new LocalizedException(__('No addresses found for the customer.'));
                }
                $address = null;
                foreach ($addresses as $addr) {
                    if ($addr->isDefaultShipping()) {
                        $address = $addr;
                        break;
                    }
                }
                if (!$address) {
                    $address = reset($addresses);
                }
            }

            // Try to obtain latitude and longitude from the address custom attributes.
            $latitude = null;
            $longitude = null;
            $customAttributes = $address->getCustomAttributes();
            if (isset($customAttributes['latitude'])) {
                $latitude = $customAttributes['latitude']->getValue();
            }
            if (isset($customAttributes['longitude'])) {
                $longitude = $customAttributes['longitude']->getValue();
            }

            // If coordinates are missing, perform geocoding.
            if (!$latitude || !$longitude) {
                // Build a full address string.
                $street = $address->getStreet();
                $streetStr = is_array($street) ? implode(', ', $street) : $street;
                $city = $address->getCity();
                $region = $address->getRegion();
                $postcode = $address->getPostcode();
                $countryId = $address->getCountryId();
                $fullAddress = implode(', ', array_filter([$streetStr, $city, $region, $postcode, $countryId]));

                // Retrieve the API key from configuration.
                $apiKey = $this->scopeConfig->getValue(
                    'madar_shiplus/general/referrer_api_key',
                    ScopeInterface::SCOPE_STORE
                );

                $geocodeUrl = "https://maps.googleapis.com/maps/api/geocode/json?address="
                    . urlencode($fullAddress) . "&key=" . $apiKey;

                $this->curl->get($geocodeUrl);
                $response = $this->curl->getBody();
                $data = json_decode($response, true);

                if ($data && isset($data['status']) && $data['status'] === 'OK') {
                    $location = $data['results'][0]['geometry']['location'];
                    $latitude = $location['lat'];
                    $longitude = $location['lng'];

                    // Save these coordinates to the customer address for future use.
                    $address->setCustomAttribute('latitude', $latitude);
                    $address->setCustomAttribute('longitude', $longitude);
                    $this->addressRepository->save($address);
                } else {
                    throw new LocalizedException(__('Geocoding failed. Unable to obtain coordinates.'));
                }
            }

            $result->setData([
                'success'   => true,
                'latitude'  => $latitude,
                'longitude' => $longitude,
                'message'   => __('Coordinates retrieved successfully.')
            ]);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            $result->setData([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }

        return $result;
    }
}
