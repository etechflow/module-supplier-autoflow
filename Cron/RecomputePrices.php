<?php

declare(strict_types=1);

namespace ETechFlow\SupplierAutoflow\Cron;

use ETechFlow\SupplierAutoflow\Model\AuditLogger;
use ETechFlow\SupplierAutoflow\Model\AutoToggleService;
use ETechFlow\SupplierAutoflow\Model\Config;
use ETechFlow\SupplierAutoflow\Model\RepriceService;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Hourly safety-net cron that re-runs auto-toggle + reprice for every
 * simple product (v0.1.0). Catches any propagation hole the legacy
 * observer + MSI plugin missed — partial sync failures, custom modules
 * writing stock outside MSI's API, etc.
 *
 * Idempotent — re-running on a product whose state is already correct
 * is a cheap no-op (no writes, no audit row).
 */
class RecomputePrices
{
    private const PROCESS_LIMIT = 5000;

    public function __construct(
        private readonly Config $config,
        private readonly AutoToggleService $autoToggleService,
        private readonly RepriceService $repriceService,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $start  = microtime(true);
        $count  = 0;
        $errors = 0;

        try {
            $collection = $this->productCollectionFactory->create();
            $collection->addAttributeToFilter('type_id', 'simple');
            $collection->addAttributeToSelect('entity_id');
            $collection->setPageSize(self::PROCESS_LIMIT);

            foreach ($collection as $product) {
                $productId = (int) $product->getId();
                try {
                    $this->autoToggleService->evaluate($productId, AuditLogger::TRIGGER_CRON);
                    $this->repriceService->recompute($productId, AuditLogger::TRIGGER_CRON);
                    $count++;
                } catch (\Throwable $e) {
                    $errors++;
                    $this->logger->warning(
                        'ETechFlow_SupplierAutoflow: Cron evaluate failed for one product.',
                        ['product_id' => $productId, 'exception' => $e->getMessage()]
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error(
                'ETechFlow_SupplierAutoflow: Cron run failed.',
                ['exception' => $e->getMessage()]
            );
            return;
        }

        $elapsed = number_format(microtime(true) - $start, 2);
        $this->logger->info(
            "ETechFlow_SupplierAutoflow: Cron resync done — {$count} products evaluated, {$errors} errors, {$elapsed}s."
        );
    }
}
