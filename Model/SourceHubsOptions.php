<?php

namespace Madar\StockAvailability\Model;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\InventoryApi\Api\SourceRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Psr\Log\LoggerInterface;

class SourceHubsOptions implements OptionSourceInterface
{
    private $sourceRepository;
    private $logger;
    private $searchCriteriaBuilder;

    public function __construct(
        SourceRepositoryInterface $sourceRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        LoggerInterface $logger,
        array $data = []
    ) {
        $this->sourceRepository = $sourceRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->logger = $logger;
        $this->logger->info('SourceHubsOptions: Class instantiated.');
    }

    public function toOptionArray()
    {
        $this->logger->info('SourceHubsOptions: Starting toOptionArray.');

        $options = [];
        try {
            // Filter by is_hub = 1
            $this->searchCriteriaBuilder->addFilter('is_hub', 1, 'eq');
            $searchCriteria = $this->searchCriteriaBuilder->create();
            $this->logger->info('SourceHubsOptions: SearchCriteria with is_hub filter created.');

            // Get sources list from repository
            $sources = $this->sourceRepository->getList($searchCriteria)->getItems();

            // Log the count of sources fetched
            if (is_array($sources) && !empty($sources)) {
                $this->logger->info(sprintf('SourceHubsOptions: %d sources found.', count($sources)));
            } else {
                $this->logger->warning('SourceHubsOptions: No sources found or sources not returned as array.');
            }

            // Iterate over the sources and add them to options
            foreach ($sources as $source) {
                if ($source && $source->getSourceCode()) {
                    $options[] = [
                        'label' => $source->getName(),
                        'value' => $source->getSourceCode(),
                    ];
                    $this->logger->info(
                        sprintf(
                            'SourceHubsOptions: Added hub option - Label: %s, Value: %s',
                            $source->getName(),
                            $source->getSourceCode()
                        )
                    );
                } else {
                    $this->logger->warning('SourceHubsOptions: Source object was null or missing a source code.');
                }
            }

            // Final log to indicate how many options were created
            $this->logger->info('SourceHubsOptions: Fetched ' . count($options) . ' hub options.');
        } catch (NoSuchEntityException $e) {
            $this->logger->error('SourceHubsOptions: Error fetching sources: ' . $e->getMessage());
        } catch (\Exception $e) {
            $this->logger->error('SourceHubsOptions: Unexpected error: ' . $e->getMessage());
        }

        return $options;
    }
}
