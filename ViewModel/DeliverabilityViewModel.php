<?php

namespace Madar\StockAvailability\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Framework\DataObject\IdentityInterface;
use Madar\StockAvailability\Helper\StockHelper;
use Magento\Catalog\Api\ProductRepositoryInterface;

class DeliverabilityViewModel implements ArgumentInterface, IdentityInterface
{
    protected $stockHelper;
    protected $productRepository;
    protected $productId;

    public function __construct(
        StockHelper $stockHelper,
        ProductRepositoryInterface $productRepository
    ) {
        $this->stockHelper = $stockHelper;
        $this->productRepository = $productRepository;
    }

    /**
     * Set the product ID for which deliverability is checked
     *
     * @param int $productId
     * @return $this
     */
    public function setProductId(int $productId)
    {
        $this->productId = $productId;
        return $this;
    }

    /**
     * Get deliverability data
     *
     * @return array
     */
    public function getDeliverabilityData(): array
    {
        if (!$this->productId) {
            return [];
        }

        return $this->stockHelper->getDeliverabilityDataByProductId($this->productId);
    }

    /**
     * Return cache tags
     *
     * @return array
     */
    public function getIdentities()
    {
        // Example cache tag, adjust as necessary
        return ['p_' . $this->productId];
    }
}
