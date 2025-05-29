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
                if ($isStrictProcessMode && !$subProduct->getQty() && $subProduct->isSalable() && $subProduct->getData('is_deliverable')) {
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
