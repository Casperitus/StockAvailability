<?php

namespace Madar\StockAvailability\Test\Unit\Plugin\Quote;

use Madar\StockAvailability\Helper\StockHelper;
use Madar\StockAvailability\Model\Session;
use Madar\StockAvailability\Plugin\Quote\ValidateDeliverability;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Quote\Model\QuoteManagement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ValidateDeliverabilityTest extends TestCase
{
    /** @var StockHelper|MockObject */
    private $stockHelper;

    /** @var Session|MockObject */
    private $session;

    /** @var LoggerInterface|MockObject */
    private $logger;

    private ValidateDeliverability $plugin;

    protected function setUp(): void
    {
        $this->stockHelper = $this->createMock(StockHelper::class);
        $this->session = $this->createMock(Session::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->plugin = new ValidateDeliverability(
            $this->stockHelper,
            $this->session,
            $this->logger
        );
    }

    public function testBeforeSubmitThrowsWhenNoBranchSelected(): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->expects($this->never())->method('getAllVisibleItems');

        $this->session->expects($this->once())
            ->method('getData')
            ->with('selected_source_code')
            ->willReturn(null);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('Please choose a delivery branch before placing your order.');

        $this->plugin->beforeSubmit(
            $this->createMock(QuoteManagement::class),
            $quote
        );
    }

    public function testBeforeSubmitThrowsWhenItemUndeliverable(): void
    {
        $quoteItem = $this->createConfiguredMock(QuoteItem::class, [
            'getSku' => 'SKU-001',
            'getName' => 'Sample Product',
        ]);

        $quote = $this->createMock(Quote::class);
        $quote->expects($this->once())
            ->method('getAllVisibleItems')
            ->willReturn([$quoteItem]);

        $this->session->expects($this->once())
            ->method('getData')
            ->with('selected_source_code')
            ->willReturn('BRANCH-1');

        $this->stockHelper->expects($this->once())
            ->method('isProductDeliverable')
            ->with('SKU-001', 'BRANCH-1')
            ->willReturn(false);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            'The item "Sample Product" cannot be delivered from the selected branch. Please remove it or choose another branch.'
        );

        $this->plugin->beforeSubmit(
            $this->createMock(QuoteManagement::class),
            $quote
        );
    }

    public function testBeforeSubmitPassesWhenAllItemsDeliverable(): void
    {
        $quoteItem = $this->createConfiguredMock(QuoteItem::class, [
            'getSku' => 'SKU-002',
            'getName' => 'Deliverable Product',
        ]);

        $quote = $this->createMock(Quote::class);
        $quote->expects($this->once())
            ->method('getAllVisibleItems')
            ->willReturn([$quoteItem]);

        $this->session->expects($this->once())
            ->method('getData')
            ->with('selected_source_code')
            ->willReturn('BRANCH-2');

        $this->stockHelper->expects($this->once())
            ->method('isProductDeliverable')
            ->with('SKU-002', 'BRANCH-2')
            ->willReturn(true);

        $orderData = ['foo' => 'bar'];

        $result = $this->plugin->beforeSubmit(
            $this->createMock(QuoteManagement::class),
            $quote,
            $orderData
        );

        $this->assertSame([$quote, $orderData], $result);
    }
}
