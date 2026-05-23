<?php

declare(strict_types=1);

namespace ETechFlow\SupplierAutoflow\Console\Command;

use ETechFlow\SupplierAutoflow\Model\AuditLogger;
use ETechFlow\SupplierAutoflow\Model\AutoToggleService;
use ETechFlow\SupplierAutoflow\Model\RepriceService;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\NoSuchEntityException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 *   bin/magento etechflow:autoflow:resync                  # all simple products
 *   bin/magento etechflow:autoflow:resync --sku=ABC,DEF    # specific SKUs only
 *
 * Runs auto-toggle + reprice idempotently across the catalogue. Use after
 * upgrading the module, after a config change, or when the merchant
 * suspects drift between supplier state and prices.
 */
class ResyncCommand extends Command
{
    public function __construct(
        private readonly AutoToggleService $autoToggleService,
        private readonly RepriceService $repriceService,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly AppState $appState
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('etechflow:autoflow:resync')
            ->setDescription('Re-run supplier auto-toggle + price recomputation across the catalogue. Idempotent.')
            ->addOption(
                'sku',
                's',
                InputOption::VALUE_REQUIRED,
                'Comma-separated SKUs to limit the resync. Default: all simple products.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->appState->getAreaCode();
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->appState->setAreaCode(Area::AREA_CRONTAB);
        }

        $skuOption = (string) $input->getOption('sku');
        $productIds = $this->resolveProductIds($skuOption, $output);

        if (empty($productIds)) {
            $output->writeln('<error>No products matched the filter. Nothing to do.</error>');
            return Command::FAILURE;
        }

        $total = count($productIds);
        $output->writeln("<info>Resyncing {$total} product(s)...</info>");
        $output->writeln('');

        $count  = 0;
        $errors = 0;
        $start  = microtime(true);

        foreach ($productIds as $productId) {
            try {
                $this->autoToggleService->evaluate((int) $productId, AuditLogger::TRIGGER_CLI_RESYNC);
                $this->repriceService->recompute((int) $productId, AuditLogger::TRIGGER_CLI_RESYNC);
                $count++;
                if ($count % 100 === 0) {
                    $output->writeln("  ... {$count}/{$total}");
                }
            } catch (\Throwable $e) {
                $errors++;
                $output->writeln("  <error>product_id={$productId} failed: {$e->getMessage()}</error>");
            }
        }

        $elapsed = number_format(microtime(true) - $start, 2);
        $output->writeln('');
        $output->writeln("<info>Done. Evaluated: {$count}, errors: {$errors}, elapsed: {$elapsed}s.</info>");

        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return int[]
     */
    private function resolveProductIds(string $skuOption, OutputInterface $output): array
    {
        if ($skuOption !== '') {
            $skus = array_filter(array_map('trim', explode(',', $skuOption)));
            $ids = [];
            foreach ($skus as $sku) {
                try {
                    $product = $this->productRepository->get($sku);
                    $ids[] = (int) $product->getId();
                } catch (NoSuchEntityException $e) {
                    $output->writeln("  <comment>SKU '{$sku}' not found — skipped.</comment>");
                }
            }
            return $ids;
        }

        $collection = $this->productCollectionFactory->create();
        $collection->addAttributeToFilter('type_id', 'simple');
        $collection->addAttributeToSelect('entity_id');

        $ids = [];
        foreach ($collection as $product) {
            $ids[] = (int) $product->getId();
        }
        return $ids;
    }
}
