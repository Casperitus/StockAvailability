<?php

namespace Madar\StockAvailability\Plugin\Quote;

use Madar\StockAvailability\Helper\StockHelper;
use Madar\StockAvailability\Model\Session;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteManagement;
use Psr\Log\LoggerInterface;

class ValidateDeliverability
{
    private StockHelper $stockHelper;

    private Session $session;

    private LoggerInterface $logger;

    public function __construct(
        StockHelper $stockHelper,
        Session $session,
        LoggerInterface $logger
    ) {
        $this->stockHelper = $stockHelper;
        $this->session = $session;
        $this->logger = $logger;
    }

    /**
     * @param QuoteManagement $subject
     * @param Quote $quote
     * @param array $orderData
     * @return array|null
     * @throws LocalizedException
     */
    public function beforeSubmit(
        QuoteManagement $subject,
        Quote $quote,
        array $orderData = []
    ): ?array {
        $sourceCode = (string)($this->session->getData('selected_source_code') ?? '');
        $sourceCode = trim($sourceCode);

        if ($sourceCode === '') {
            throw new LocalizedException(
                __('Please choose a delivery branch before placing your order.')
            );
        }

        foreach ($quote->getAllVisibleItems() as $item) {
            $sku = (string)$item->getSku();
            if ($sku === '') {
                $this->logger->warning('Quote item without SKU encountered during deliverability validation.');
                continue;
            }

            if (!$this->stockHelper->isProductDeliverable($sku, $sourceCode)) {
                $productName = $item->getName() ?: $sku;
                throw new LocalizedException(
                    __(
                        'The item "%1" cannot be delivered from the selected branch. Please remove it or choose another branch.',
                        $productName
                    )
                );
            }
        }

        return [$quote, $orderData];
    }
}
