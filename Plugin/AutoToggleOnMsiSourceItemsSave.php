<?php

declare(strict_types=1);

namespace ETechFlow\SupplierAutoflow\Plugin;

use ETechFlow\SupplierAutoflow\Model\AuditLogger;
use ETechFlow\SupplierAutoflow\Model\AutoToggleService;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * MSI source-items save hook (v0.1.0). Mirrors NDE v1.6.0's pattern:
 * legacy `cataloginventory_stock_item_save_after` doesn't fire reliably
 * for MSI flows (order shipment, refund restock, REST API source-items
 * save, bulk imports). We hook MSI's SourceItemsSaveInterface directly.
 *
 * Soft-installed via di.xml: if MSI's interface doesn't exist on the
 * target install, Magento ignores the <type> declaration. The plugin
 * never runs and the module continues to work via the legacy observer.
 */
class AutoToggleOnMsiSourceItemsSave
{
    public function __construct(
        private readonly AutoToggleService $autoToggleService,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param object $subject     Magento\InventoryApi\Api\SourceItemsSaveInterface
     * @param mixed  $result      Return value of execute()
     * @param array  $sourceItems Saved source items
     */
    public function afterExecute($subject, $result, array $sourceItems = [])
    {
        if (empty($sourceItems)) {
            return $result;
        }

        $skus = [];
        foreach ($sourceItems as $item) {
            try {
                $sku = method_exists($item, 'getSku') ? (string) $item->getSku() : '';
            } catch (\Throwable $e) {
                continue;
            }
            if ($sku !== '') {
                $skus[$sku] = true;
            }
        }

        foreach (array_keys($skus) as $sku) {
            try {
                $product = $this->productRepository->get($sku);
                $productId = (int) $product->getId();
                if ($productId > 0) {
                    $this->autoToggleService->evaluate($productId, AuditLogger::TRIGGER_MSI_SOURCE_SAVE);
                }
            } catch (NoSuchEntityException $e) {
                continue;
            } catch (\Throwable $e) {
                $this->logger->error(
                    'ETechFlow_SupplierAutoflow: auto-toggle on MSI save failed.',
                    ['sku' => $sku, 'exception' => $e->getMessage()]
                );
            }
        }

        return $result;
    }
}
