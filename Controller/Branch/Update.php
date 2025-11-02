<?php

namespace Madar\StockAvailability\Controller\Branch;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Madar\StockAvailability\Model\LocationManager;
use Psr\Log\LoggerInterface;

class Update extends Action
{
    protected $resultJsonFactory;
    protected LocationManager $locationManager;
    protected LoggerInterface $logger;

    public function __construct(
        Context $context,
        JsonFactory $resultJsonFactory,
        LocationManager $locationManager,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->resultJsonFactory = $resultJsonFactory;
        $this->locationManager = $locationManager;
        $this->logger = $logger;
    }

    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $data = json_decode($this->getRequest()->getContent(), true);

        if (!is_array($data)) {
            return $result->setData([
                'success' => false,
                'message' => 'Invalid JSON payload.'
            ]);
        }
        try {
            $response = $this->locationManager->persistLocation($data);
            $branchData = $response['branch'] ?? $this->locationManager->getBranchData();

            $payload = [
                'success' => true,
                'message' => 'Branch details updated successfully',
                'branch' => $branchData,
                'shipping_address' => $response['shipping_address'] ?? [],
                'prefill' => $response['prefill'] ?? [],
            ];

            $this->logger->info('Branch Update Session Data: ' . json_encode($branchData));

            return $result->setData($payload);
        } catch (\Exception $exception) {
            $this->logger->error('Unable to persist location: ' . $exception->getMessage());

            return $result->setData([
                'success' => false,
                'message' => __('Unable to save your location at this time. Please try again.'),
            ]);
        }
    }
}
