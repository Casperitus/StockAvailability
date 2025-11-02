<?php

namespace Madar\StockAvailability\Plugin\Hyva\Checkout;

use Hyva\Checkout\Model\Form\EntityFormService;
use Madar\StockAvailability\Model\LocationManager;

class EntityFormServicePlugin
{
    private LocationManager $locationManager;

    public function __construct(LocationManager $locationManager)
    {
        $this->locationManager = $locationManager;
    }

    /**
     * @param EntityFormService $subject
     * @param array $result
     * @param string $formCode
     * @param int|null $quoteId
     * @return array
     */
    public function afterGetFormData(EntityFormService $subject, array $result, string $formCode, $quoteId = null): array
    {
        if ($formCode !== 'shipping-address-form') {
            return $result;
        }

        $prefill = $this->locationManager->getCheckoutPrefillData();
        if (empty($prefill['shipping_address']) || !is_array($prefill['shipping_address'])) {
            return $result;
        }

        $shippingPrefill = $prefill['shipping_address'];

        if (!isset($result['entity']) || !is_array($result['entity'])) {
            $result['entity'] = [];
        }

        $result['entity'] = array_replace_recursive($result['entity'], $this->filterEmptyValues($shippingPrefill));

        if (!isset($result['entity']['custom_attributes']) || !is_array($result['entity']['custom_attributes'])) {
            $result['entity']['custom_attributes'] = [];
        }

        if (isset($shippingPrefill['latitude']) && $shippingPrefill['latitude'] !== null) {
            $result['entity']['custom_attributes']['latitude'] = $shippingPrefill['latitude'];
        }
        if (isset($shippingPrefill['longitude']) && $shippingPrefill['longitude'] !== null) {
            $result['entity']['custom_attributes']['longitude'] = $shippingPrefill['longitude'];
        }

        return $result;
    }

    private function filterEmptyValues(array $data): array
    {
        return array_filter(
            $data,
            static function ($value) {
                if (is_array($value)) {
                    return count(array_filter($value, static fn($item) => $item !== null && $item !== '')) > 0;
                }

                return $value !== null && $value !== '';
            }
        );
    }
}
