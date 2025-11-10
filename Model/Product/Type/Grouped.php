<?php

namespace Madar\StockAvailability\Model\Product\Type;

use Magento\GroupedProduct\Model\Product\Type\Grouped as MagentoGrouped;

class Grouped extends MagentoGrouped
{
    /**
     * Override the getProductInfo method to include custom deliverability logic.
     */
    protected function getProductInfo(\Magento\Framework\DataObject $buyRequest, $product, $isStrictProcessMode)
    {
        $productsInfo = $buyRequest->getSuperGroup() ?: [];
        $associatedProducts = $this->getAssociatedProducts($product);

        if (!is_array($productsInfo)) {
            return __('Please specify the quantity of product(s).')->render();
        }

        foreach ($associatedProducts as $subProduct) {
            if (!isset($productsInfo[$subProduct->getId()])) {
                $isDeliverableFlag = $subProduct->getData('is_deliverable_flag');
                $deliverableValue = $subProduct->getData('is_deliverable');

                if ($isDeliverableFlag !== null) {
                    $isDeliverable = (bool) $isDeliverableFlag;
                } elseif ($deliverableValue === null) {
                    $isDeliverable = true;
                } elseif (is_bool($deliverableValue)) {
                    $isDeliverable = $deliverableValue;
                } elseif (is_string($deliverableValue)) {
                    $isDeliverable = strcasecmp($deliverableValue, 'Deliverable') === 0;
                } else {
                    $isDeliverable = ((int) $deliverableValue) === 1;
                }

                if ($isStrictProcessMode && !$subProduct->getQty() && $subProduct->isSalable() && $isDeliverable) {
                    return __('Please specify the quantity of product(s).')->render();
                }

                if (isset($buyRequest['qty']) && !isset($buyRequest['super_group'])) {
                    $subProductQty = (float)$subProduct->getQty() * (float)$buyRequest['qty'];
                    $productsInfo[$subProduct->getId()] = $subProduct->isSalable() ? $subProductQty : 0;
                } else {
                    $productsInfo[$subProduct->getId()] = $subProduct->isSalable() ? (float)$subProduct->getQty() : 0;
                }
            }
        }

        return $productsInfo;
    }
}
