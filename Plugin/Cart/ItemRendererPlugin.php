<?php

namespace Madar\StockAvailability\Plugin\Cart;

use Magento\Checkout\Block\Cart\Item\Renderer as ItemRenderer;
use Madar\StockAvailability\Helper\StockHelper;
use Madar\StockAvailability\Model\Session as CustomerSession;
use Psr\Log\LoggerInterface;

class ItemRendererPlugin
{
    /**
     * @var StockHelper
     */
    protected $stockHelper;

    /**
     * @var CustomerSession
     */
    protected $customerSession;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Constructor
     *
     * @param StockHelper $stockHelper
     * @param CustomerSession $customerSession
     * @param LoggerInterface $logger
     */
    public function __construct(
        StockHelper $stockHelper,
        CustomerSession $customerSession,
        LoggerInterface $logger
    ) {
        $this->stockHelper = $stockHelper;
        $this->customerSession = $customerSession;
        $this->logger = $logger;
    }

    /**
     * After plugin to append deliverability status to cart item data.
     *
     * @param ItemRenderer $subject
     * @param array $result
     * @return array
     */
    public function afterGetItemData(ItemRenderer $subject, array $result): array
    {
        $item = $subject->getItem();
        $product = $item->getProduct();
        $sku = $product->getSku();

        $this->logger->info("Processing cart item with SKU: {$sku}.");

        // Retrieve source code from customer session
        $deliveryBranchData = $this->customerSession->getData();
        $sourceCode = $deliveryBranchData['selected_source_code'] ?? null;

        if ($sourceCode) {
            $this->logger->info("Source code found in session: {$sourceCode} for SKU: {$sku}.");
            $isDeliverable = $this->stockHelper->isProductDeliverable($sku, $sourceCode);
            $this->logger->info("Deliverability status for SKU: {$sku} via Source: {$sourceCode} is " . ($isDeliverable ? 'TRUE' : 'FALSE') . ".");
        } else {
            $this->logger->warning("No source code found in session for SKU: {$sku}. Assuming deliverable.");
            $isDeliverable = true; // Assume deliverable if no source code selected
        }

        // Append deliverability status
        $result['is_deliverable'] = $isDeliverable;

        $this->logger->debug("Updated item data for SKU: {$sku} with 'is_deliverable': {$isDeliverable}.");

        return $result;
    }
}
