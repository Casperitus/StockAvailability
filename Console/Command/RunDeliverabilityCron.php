<?php

namespace Madar\StockAvailability\Console\Command;

use Madar\StockAvailability\Cron\DeliverabilityCron;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RunDeliverabilityCron extends Command
{
    private State $appState;
    private DeliverabilityCron $deliverabilityCron;
    private LoggerInterface $logger;

    public function __construct(
        State $appState,
        DeliverabilityCron $deliverabilityCron,
        LoggerInterface $logger
    ) {
        $this->appState = $appState;
        $this->deliverabilityCron = $deliverabilityCron;
        $this->logger = $logger;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('madar:deliverability:run')
            ->setDescription('Run the Madar product deliverability cron job');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        try {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        } catch (\Exception $e) {
            // Area code might already be set; ignore.
        }

        $output->writeln('<info>Starting deliverability cron job...</info>');

        try {
            $this->deliverabilityCron->execute();
            $output->writeln('<info>Deliverability cron job completed successfully.</info>');
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->logger->error('[RunDeliverabilityCron] Cron execution failed: ' . $e->getMessage());
            $output->writeln('<error>Deliverability cron job failed. Check logs for details.</error>');
            return Command::FAILURE;
        }
    }
}
